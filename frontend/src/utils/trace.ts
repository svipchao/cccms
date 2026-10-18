/**
 * 请求标识（前端侧）：**请求 / 操作 / 会话**三层，各管一件事。
 *
 * | ID | 边界 | 用途 |
 * |----|------|------|
 * | `trace_id` | 一次 HTTP 请求（后端生成） | 报错时定位到具体某条记录 |
 * | `action_id` | 一次用户操作（本文件生成） | 链路视图：一次操作发了哪些请求、哪步失败 |
 * | `session_id` | 一次页面会话，**刷新即换**（本文件生成） | 审计视角：这一段时间先后做了什么 |
 *
 * 为什么请求级 ID 不由这里生成：一个请求只有一条日志，按请求做"链"永远只有一行；
 * 而一次操作（进页面并发拉数据 / 保存 + 回读）的多个请求才有因果关系 —— 那才是
 * 排障时想看的东西。
 *
 * ## action 的边界（确定性规则，不靠时间窗猜）
 *
 * 1. 路由进入 / 切换 → 新开（`newAction()`，由 `router.afterEach` 调用）；
 * 2. **每次写请求**（POST/PUT/DELETE/PATCH）都新开一个 action —— 写请求就是
 *    "用户明确做了某件事"的信号。这样「页面加载的一堆 GET」和「随后点保存」
 *    不会混在一条里，连续两次删除也不会被并成一次；
 * 3. 写请求之后的读请求（保存成功后刷新列表）继续归当前 action。
 *
 * session 不做任何时间判断：模块加载时生成一次，刷新页面自然换新；登出时 `reset()`。
 */

/** 写请求方法：一次写请求代表一次明确的用户动作 */
const WRITE_METHODS = new Set(['POST', 'PUT', 'DELETE', 'PATCH'])

/** 当前会话（刷新即换） */
let sessionId = newSessionId()
/** 当前操作 */
let actionId = newActionId()

/** 生成随机十六进制串（优先 WebCrypto，降级 Math.random） */
function randomHex(bytes: number): string {
  const buffer = new Uint8Array(bytes)
  const webCrypto = globalThis.crypto

  if (webCrypto && typeof webCrypto.getRandomValues === 'function') {
    webCrypto.getRandomValues(buffer)
  } else {
    for (let i = 0; i < bytes; i++) {
      buffer[i] = Math.floor(Math.random() * 256)
    }
  }

  return Array.from(buffer, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/** 形如 `a260918143012-1a2b3c4d`：前缀区分类型，时间前缀便于肉眼排序 */
function makeId(prefix: string, date: Date): string {
  const pad = (value: number): string => String(value).padStart(2, '0')
  const stamp =
    String(date.getFullYear()).slice(2) +
    pad(date.getMonth() + 1) +
    pad(date.getDate()) +
    pad(date.getHours()) +
    pad(date.getMinutes()) +
    pad(date.getSeconds())

  return `${prefix}${stamp}-${randomHex(4)}`
}

/** 新会话 ID（仅测试 / 内部使用，外部用 `resetTrace()`） */
export function newSessionId(date: Date = new Date()): string {
  return makeId('s', date)
}

/** 新操作 ID */
export function newActionId(date: Date = new Date()): string {
  return makeId('a', date)
}

/**
 * 开始一次新操作（路由进入 / 切换时调用）。
 *
 * 只换 action，不换 session：会话的边界是"刷新页面"，不是"换页面"。
 */
export function newAction(): string {
  actionId = newActionId()
  return actionId
}

/**
 * 取本次请求应携带的 `action_id` / `session_id`（请求拦截器调用）。
 *
 * 写请求一律开启新操作：它是"用户明确做了某件事"的信号；读请求只是跟随
 * 当前操作（页面加载 / 保存成功后的回读）。
 */
export function acquireTraceContext(method = 'GET'): { actionId: string; sessionId: string } {
  if (WRITE_METHODS.has(String(method).toUpperCase())) {
    newAction()
  }

  return { actionId, sessionId }
}

/** 当前操作 ID（调试 / 测试用） */
export function currentActionId(): string {
  return actionId
}

/** 当前会话 ID（调试 / 测试用） */
export function currentSessionId(): string {
  return sessionId
}

/** 重置：登出 / 换账号时调用 —— 会话与操作都必须换新，避免跨账号复用 */
export function resetTrace(): void {
  sessionId = newSessionId()
  newAction()
}
