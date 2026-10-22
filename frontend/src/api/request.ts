import axios, { type AxiosInstance, type AxiosResponse } from 'axios'
import { ElMessage } from 'element-plus'
import { currentLocale, t } from '@/locales'
import { clearToken, getToken, setToken } from '@/utils/auth'
import { progressDone, progressStart } from '@/utils/progress'
import { acquireTraceContext } from '@/utils/trace'

export interface ApiEnvelope<T = unknown> {
  code: number
  message: string
  data: T
}

/** 请求级进度条令牌：挂在 config 上，保证 start / done 一一对应 */
interface TrackedConfig {
  __progressToken?: number
}

let progressSeq = 0

// 注意用 ?? 而不是 ||：生产把 VITE_API_BASE 配成空串（同域直连后端、无前缀）时，
// || 会把空串误判为"未配置"而回退到 /api，线上就会请求 /api/xxx 导致 404。
const instance: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_BASE ?? '/api',
  timeout: 15000,
})

/** 进度条收尾（成功/失败/超时/取消都要走到），避免进度条卡住 */
function endProgress(config?: TrackedConfig): void {
  const token = config?.__progressToken
  if (token !== undefined) {
    progressDone(token)
  }
}

/**
 * 取操作 ID：优先响应体（`Result::fail` 会带上 `action_id`），其次 `trace_id`。
 *
 * 提示里给的是**操作 ID**：一次失败用户想查的是"这次操作发了什么"，
 * 而不是孤零零的一条请求；后端保留 `trace_id` 作为兜底。
 */
function responseActionId(response?: { data?: unknown }): string {
  const body = response?.data as { action_id?: unknown; trace_id?: unknown } | undefined

  if (typeof body?.action_id === 'string' && body.action_id) {
    return body.action_id
  }
  if (typeof body?.trace_id === 'string' && body.trace_id) {
    return body.trace_id
  }

  return ''
}

/** 报错文案带上操作 ID（没有则原样返回），便于复制后到日志页检索 */
function withActionId(message: string, actionId: string): string {
  return actionId ? `${message}${t('common.actionIdSuffix', { id: actionId })}` : message
}

instance.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  // 带上当前语言，后端据此返回对应语言的错误文案（优先 ?lang=，其次该请求头）
  config.headers['Accept-Language'] = currentLocale.value

  // 操作 / 会话 ID：一次操作的多个请求共用同一 action，刷新页面即换 session。
  // 写请求会开启新操作（见 utils/trace.ts 的边界规则），后端按 action 聚合成链路。
  const context = acquireTraceContext(String(config.method ?? 'GET'))
  config.headers['X-Action-Id'] = context.actionId
  config.headers['X-Session-Id'] = context.sessionId

  // 每个请求都推进度条：并发请求按令牌聚合，全部结束才收尾
  const tracked = config as typeof config & TrackedConfig
  if (tracked.__progressToken === undefined) {
    tracked.__progressToken = ++progressSeq
    progressStart(tracked.__progressToken)
  }
  return config
})

/** 401 风暴时避免反复写 hash 触发重复跳转 */
let redirecting = false

instance.interceptors.response.use(
  (response) => {
    endProgress(response.config as TrackedConfig)

    // 滑动续期：后端在令牌剩余有效期不足 1/3 时回写新令牌，这里静默替换。
    // 必须放在 blob 分支之前 —— 导出类请求也走同一个拦截器，否则会漏掉续期。
    const renewed = response.headers['x-refresh-token']
    if (typeof renewed === 'string' && renewed) {
      setToken(renewed)
    }

    // 文件下载（CSV 导出）：保留完整响应，调用方需要读 Content-Disposition 里的文件名
    if (response.config.responseType === 'blob') {
      return response as never
    }

    const body = response.data as ApiEnvelope
    if (body && typeof body === 'object' && 'code' in body) {
      if (body.code === 0) {
        return body.data as never
      }
      const failed = withActionId(body.message || t('common.requestFailed'), responseActionId(response))
      ElMessage.error(failed)
      return Promise.reject(new Error(failed))
    }
    return body as never
  },
  (error) => {
    endProgress(error?.config as TrackedConfig | undefined)

    const status = error?.response?.status
    const message = error?.response?.data?.message
    const actionId = responseActionId(error?.response)

    if (status === 401) {
      // 已登录态失效时用户看不到日志页，这里不追加操作 ID，避免提示变长
      clearToken()
      ElMessage.error(message || t('common.loginExpired'))
      if (!redirecting && !window.location.hash.startsWith('#/login')) {
        redirecting = true
        // 动态路由与标签页都在内存里：不清掉的话，换账号登录会残留上一个账号的菜单。
        // 用动态 import 避免 request ←→ router ←→ store 的循环依赖。
        void import('@/router').then(({ resetAfterLogout }) => {
          resetAfterLogout()
          window.location.hash = '#/login'
          window.setTimeout(() => {
            redirecting = false
          }, 800)
        })
      }
    } else if (status && error?.config?.responseType === 'blob' && error?.response?.data instanceof Blob) {
      // blob 下载类请求（导出文件）的错误响应体也是 Blob，读不出后端 message：
      // 异步解析出 JSON 里的 message 再提示（如「导出任务尚未完成」），
      // 而不是只给一句笼统的「请求错误(422)」
      error.response.data
        .text()
        .then((text: string) => {
          try {
            const parsed = JSON.parse(text) as { message?: string; action_id?: string; trace_id?: string }
            const failed = parsed.message || t('common.requestError', { status })
            ElMessage.error(withActionId(failed, parsed.action_id || parsed.trace_id || actionId))
          } catch {
            ElMessage.error(withActionId(t('common.requestError', { status }), actionId))
          }
        })
        .catch(() => ElMessage.error(withActionId(t('common.requestError', { status }), actionId)))
    } else if (status) {
      ElMessage.error(withActionId(message || t('common.requestError', { status }), actionId))
    } else {
      ElMessage.error(t('common.networkError'))
    }
    return Promise.reject(error)
  },
)

/**
 * 剔除空查询参数（`''` / `null` / `undefined` / 空数组）。
 *
 * 列表页的筛选条件初始值普遍是空串，直接下发会变成 `?status=`；后端查询模型里
 * 的数值字段（如 `status: int | None`）无法把空串解析成整数，直接返回 422
 * （`int_parsing`）。空串对字符串字段也等价于「不筛选」，统一在出口剔除。
 */
function compactParams(params?: Record<string, unknown>): Record<string, unknown> | undefined {
  if (!params) {
    return undefined
  }
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value === '' || value === null || value === undefined) {
      continue
    }
    if (Array.isArray(value) && value.length === 0) {
      continue
    }
    out[key] = value
  }
  return out
}

export const http = {
  get<T = unknown>(url: string, params?: Record<string, unknown>): Promise<T> {
    return instance.get(url, { params: compactParams(params) }) as unknown as Promise<T>
  },
  post<T = unknown>(url: string, data?: unknown): Promise<T> {
    return instance.post(url, data) as unknown as Promise<T>
  },
}

/** 从 Content-Disposition 解析文件名（优先 RFC 5987 的 filename*） */
function filenameFromDisposition(disposition: string): string {
  const star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(disposition)
  if (star?.[1]) {
    try {
      return decodeURIComponent(star[1].trim().replace(/^"|"$/g, ''))
    } catch {
      // 解码失败则回落到普通 filename
    }
  }

  const plain = /filename="?([^";]+)"?/i.exec(disposition)
  return plain?.[1] ? plain[1].trim() : ''
}

/** 触发 blob 下载：文件名取 Content-Disposition，拿不到时用 fallbackName */
function triggerBlobDownload(response: AxiosResponse<Blob>, fallbackName: string): void {
  const name = filenameFromDisposition(String(response.headers['content-disposition'] ?? '')) || fallbackName
  const objectUrl = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}

/**
 * 下载后端返回的文件（CSV 导出）。
 *
 * 复用同一个 axios 实例：自动带鉴权头、401 处理与进度条；
 * 文件名取 `Content-Disposition`，拿不到时用 `fallbackName`。
 */
export async function downloadFile(
  url: string,
  params?: Record<string, unknown>,
  fallbackName = 'export.csv',
): Promise<void> {
  const response = (await instance.get(url, {
    params: compactParams(params),
    responseType: 'blob',
  })) as unknown as AxiosResponse<Blob>
  triggerBlobDownload(response, fallbackName)
}

/**
 * POST 版文件下载（导出模板等需要带 body 参数的接口）。
 *
 * 返回后端 `X-Export-Rows` 响应头里的导出行数（拿不到返回 null），
 * 调用方可据此提示「导出成功，共 N 条」。
 */
export async function downloadFileByPost(
  url: string,
  data?: unknown,
  fallbackName = 'export.xlsx',
): Promise<number | null> {
  const response = (await instance.post(url, data, { responseType: 'blob' })) as unknown as AxiosResponse<Blob>
  triggerBlobDownload(response, fallbackName)

  const total = Number(response.headers['x-export-rows'] ?? '')
  return total > 0 ? total : null
}

export default instance
