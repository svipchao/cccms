/**
 * 全局顶部进度条（NProgress）统一入口。
 *
 * 三处共用同一条进度条：**路由切换 / HTTP 请求 / 附件上传**，
 * 所以配置和并发计数必须集中在这里，各自直接调 NProgress 会互相打架。
 *
 * 并发用「令牌集合」聚合：
 * - 每个任务登记一个令牌，**全部令牌都完成**才收尾 —— 避免先返回的请求
 *   把还没结束的任务的进度条提前关掉；
 * - 同一令牌重复 start 幂等（路由守卫返回 redirect 会二次触发 `beforeEach`）、
 *   未知令牌 done 直接忽略，因此既不会卡死也不会提前消失。
 *
 * 样式与主色覆盖见 `assets/styles/app.css`，基础样式在 `main.ts` 引入。
 */
import NProgress from 'nprogress'

NProgress.configure({
  showSpinner: false,
  trickleSpeed: 120,
  minimum: 0.15,
  speed: 300,
})

/** 正在进行的任务令牌 */
const pending = new Set<unknown>()

/**
 * 进度条的「出现延迟」。
 *
 * 本地 / 内网接口通常几十毫秒就回来，页面 chunk 也常常已经在缓存里 ——
 * 这种情况下 start() + done() 落在同两帧内，用户看到的是一道**闪一下的光条**，
 * 纯属视觉噪音（还伴随一次样式计算与重绘）。
 * 延迟 120ms 再显示：快任务干脆不显示，慢任务该提示的一点不少。
 */
const SHOW_DELAY_MS = 120

let showTimer: number | null = null

/** 路由令牌：同一时刻只会有一次导航在跑，固定复用即可 */
export const ROUTE_PROGRESS = 'route'

/** 上传令牌：el-upload 走原生 XHR，不经过 axios，需要单独登记 */
export const UPLOAD_PROGRESS = 'upload'

/** 登记一个任务；首个任务才真正显示进度条 */
export function progressStart(token: unknown = ROUTE_PROGRESS): void {
  if (pending.has(token)) {
    return
  }
  const first = pending.size === 0
  pending.add(token)
  if (first) {
    showTimer = window.setTimeout(() => {
      showTimer = null
      // 定时器到点时任务可能已经结束了
      if (pending.size > 0) {
        NProgress.start()
      }
    }, SHOW_DELAY_MS)
  }
}

/** 注销一个任务；最后一个任务结束才收尾 */
export function progressDone(token: unknown = ROUTE_PROGRESS): void {
  if (!pending.delete(token)) {
    return
  }
  if (pending.size === 0) {
    if (showTimer !== null) {
      window.clearTimeout(showTimer)
      showTimer = null
    }
    // 未 start 时 done() 是空操作（nprogress 内部会先判 status）
    NProgress.done()
  }
}
