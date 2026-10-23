import { onUnmounted, readonly, ref } from 'vue'
import { useUserStore } from '@/stores/user'

/**
 * 实时推送（F9）：SSE / WebSocket 双通道客户端。
 *
 * ## 两种 driver 是等价的
 *
 * 服务端两条通道共用 `Realtime` 事件源，事件名与载荷一致（见 `process/Sse.php` 与
 * `process/Websocket.php`）。这里把两边都还原成 `{ id, event, data }` 再分发，
 * 所以订阅方（`useRealtime().on()`）完全不需要知道底层用的是哪种通道。
 *
 * 默认 `sse`（后台只需服务端下行）；需要客户端上行时构建期指定
 * `VITE_REALTIME_DRIVER=ws`。地址同样可覆盖：`VITE_SSE_URL` / `VITE_WS_URL`。
 *
 * ## 为什么不用浏览器的「自动重连」
 *
 * `EventSource` 自带重连，但它重连时**不会**带 `Last-Event-ID` 之外的任何控制：
 * 我们需要自己传 `last_id`（后端的补发窗口按事件 id 过滤），还要能在登出 / 被踢时
 * 彻底停掉。所以统一走「自己 close + 自己退避重连」。`WebSocket` 没有自动重连，
 * 这里复用同一套退避逻辑。
 *
 * ## 令牌怎么带
 *
 * 两种通道都不支持自定义请求头，令牌只能放 query（`?token=`）。为防止它出现在
 * 代理的 access log 里，部署文档要求对推送地址关掉日志或单独反代。
 *
 * ## 连接是单例
 *
 * 整个后台只有一条连接：`connectRealtime()` 幂等，重复调用不会开第二条。
 * 每个标签页各自一条（浏览器不共享），这是长连接的正常形态。
 */

export type RealtimeEventName = 'notice' | 'job' | 'logout'

type Handler = (data: Record<string, unknown>) => void
type Transport = 'sse' | 'ws'

/** 只订阅这几个事件：服务端推别的事件名会被忽略（避免协议漂移；心跳 ping/pong 也在这里被丢掉） */
const EVENT_NAMES: RealtimeEventName[] = ['notice', 'job', 'logout']

const connected = ref(false)
const handlers = new Map<string, Set<Handler>>()

const transport: Transport = (() => {
  const configured = (import.meta.env.VITE_REALTIME_DRIVER as string | undefined) ?? ''

  return configured.toLowerCase() === 'ws' ? 'ws' : 'sse'
})()

let source: EventSource | null = null
let socket: WebSocket | null = null
let retryTimer: number | null = null
let retryDelay = 1000
let lastEventId = 0

/**
 * SSE 地址。
 *
 * 默认直连独立端口 8788（不能是 8787：那是后端 HTTP 服务）。
 * 生产建议用 `VITE_SSE_URL=/sse/stream` 走同域反代 —— https 页面直连 http 端口会被浏览器
 * 当作混合内容拦掉，前端只能拿到一个永远连不上的 EventSource。
 */
function sseEndpoint(): string {
  const configured = (import.meta.env.VITE_SSE_URL as string | undefined) ?? ''
  if (configured) {
    return configured
  }
  const protocol = location.protocol === 'https:' ? 'https:' : 'http:'

  return `${protocol}//${location.hostname}:8788/stream`
}

/**
 * WebSocket 地址。
 *
 * 默认直连 8789（与 SSE 的 8788 分开，一个端口只能跑一种应用层协议）。
 * 生产同样建议走同域反代：`VITE_WS_URL=/ws/stream`，协议由 `ws` / `wss` 自动匹配 ——
 * https 页面连 `ws://` 会被当作混合内容拦掉。
 */
function wsEndpoint(): string {
  const configured = (import.meta.env.VITE_WS_URL as string | undefined) ?? ''
  if (configured) {
    // 相对地址（如 /ws/stream）按当前页面的 ws/wss 协议补全
    if (configured.startsWith('/')) {
      const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:'

      return `${protocol}//${location.host}${configured}`
    }

    return configured
  }
  const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:'

  return `${protocol}//${location.hostname}:8789/ws`
}

/** 从 JWT 里取 jti：判断「被踢的是不是我这一个令牌」，同账号其它设备不该被误登出 */
function tokenJti(token: string): string {
  try {
    const part = token.split('.')[1] ?? ''
    const normalized = part.replace(/-/g, '+').replace(/_/g, '/')
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4)
    const payload = JSON.parse(atob(padded)) as { jti?: string }

    return String(payload.jti ?? '')
  } catch {
    return ''
  }
}

function emit(name: string, data: Record<string, unknown>): void {
  const set = handlers.get(name)
  if (!set) {
    return
  }
  for (const handler of set) {
    try {
      handler(data)
    } catch {
      // 单个订阅者出错不影响其它订阅者
    }
  }
}

function scheduleReconnect(): void {
  if (retryTimer !== null) {
    return
  }
  retryTimer = window.setTimeout(() => {
    retryTimer = null
    connectRealtime()
  }, retryDelay)
  // 1s → 2s → 4s … 最多 30s：断网 30 秒恢复后仍能自动连上
  retryDelay = Math.min(retryDelay * 2, 30_000)
}

/** 事件分发（两种通道共用）：只认白名单里的事件名 */
function dispatch(id: number, name: string, data: Record<string, unknown>): void {
  if (id > lastEventId) {
    lastEventId = id
  }
  if (!EVENT_NAMES.includes(name as RealtimeEventName)) {
    return
  }
  emit(name, data)
}

function connectSource(token: string): void {
  let url: URL
  try {
    url = new URL(sseEndpoint(), location.origin)
  } catch {
    return
  }
  url.searchParams.set('token', token)
  if (lastEventId > 0) {
    url.searchParams.set('last_id', String(lastEventId))
  }

  const current = new EventSource(url.toString())
  source = current

  current.addEventListener('open', () => {
    connected.value = true
    retryDelay = 1000
  })

  for (const name of EVENT_NAMES) {
    current.addEventListener(name, (event) => {
      const message = event as MessageEvent<string>
      let data: Record<string, unknown> = {}
      try {
        data = JSON.parse(message.data) as Record<string, unknown>
      } catch {
        // 非 JSON 的数据按空对象处理（心跳之类不该走到这里，但别让一条坏帧断掉整条连接）
      }
      dispatch(Number(message.lastEventId || 0), name, data)
    })
  }

  current.onerror = () => {
    connected.value = false
    // 主动断开（disconnectRealtime 已把 source 置空）或已被新连接替换：不再重连
    if (source !== current) {
      return
    }
    source = null
    current.close()
    scheduleReconnect()
  }
}

function connectSocket(token: string): void {
  let url: URL
  try {
    url = new URL(wsEndpoint(), location.origin)
  } catch {
    return
  }
  url.searchParams.set('token', token)
  if (lastEventId > 0) {
    url.searchParams.set('last_id', String(lastEventId))
  }

  let current: WebSocket
  try {
    current = new WebSocket(url.toString())
  } catch {
    // 构造失败（地址非法 / 被策略拦截）也要走退避，否则一次失败就永久静默
    scheduleReconnect()

    return
  }
  socket = current

  current.onopen = () => {
    connected.value = true
    retryDelay = 1000
  }

  current.onmessage = (event) => {
    let payload: { id?: unknown; event?: unknown; data?: unknown }
    try {
      payload = JSON.parse(String(event.data)) as typeof payload
    } catch {
      return
    }

    const data =
      typeof payload.data === 'object' && payload.data !== null ? (payload.data as Record<string, unknown>) : {}

    dispatch(Number(payload.id ?? 0), String(payload.event ?? ''), data)
  }

  current.onclose = () => {
    connected.value = false
    // 主动断开或已被新连接替换时 socket 已置空，这里不能再触发重连（否则登出后会自动连回来）
    if (socket !== current) {
      return
    }
    socket = null
    scheduleReconnect()
  }
}

/** 建立连接（幂等）：无令牌 / 已连接时直接返回 */
export function connectRealtime(): void {
  if (source || socket) {
    return
  }

  const userStore = useUserStore()
  if (!userStore.token) {
    return
  }

  if (transport === 'ws') {
    connectSocket(userStore.token)

    return
  }

  connectSource(userStore.token)
}

/** 断开并停止重连（登出 / 被踢时调用） */
export function disconnectRealtime(): void {
  // 先置空再 close：close 触发的 onclose / onerror 会据此判断「这是主动断开」
  const currentSource = source
  source = null
  currentSource?.close()

  const currentSocket = socket
  socket = null
  currentSocket?.close()

  connected.value = false
  if (retryTimer !== null) {
    window.clearTimeout(retryTimer)
    retryTimer = null
  }
}

/** 订阅事件；返回取消订阅函数 */
export function onRealtime(name: RealtimeEventName, handler: Handler): () => void {
  const set = handlers.get(name) ?? new Set<Handler>()
  set.add(handler)
  handlers.set(name, set)

  return () => set.delete(handler)
}

/** 组件内使用：卸载时自动退订 */
export function useRealtime() {
  const cleanups: (() => void)[] = []

  function on(name: RealtimeEventName, handler: Handler): void {
    cleanups.push(onRealtime(name, handler))
  }

  onUnmounted(() => {
    for (const cleanup of cleanups) {
      cleanup()
    }
  })

  return { on, connected: readonly(connected), connect: connectRealtime, disconnect: disconnectRealtime }
}

export { tokenJti }
