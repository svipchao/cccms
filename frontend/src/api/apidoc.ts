import axios from 'axios'
import { http } from './request'
import { getToken } from '@/utils/auth'
import { currentLocale } from '@/locales'

/** 树节点上的轻量接口信息（不含参数，点开才取详情） */
export interface ApiDocBrief {
  id: string
  method: string
  path: string
  summary: string
  /** 权限节点；null 表示公开接口（NoLogin / NoAuth） */
  slug: string | null
  noLogin: boolean
  noAuth: boolean
}

export interface ApiDocGroup {
  key: string
  title: string
  /** 所属插件目录名（plugin/{plugin}），左侧树的第一层 */
  plugin: string
  /** 插件显示名（服务端按语言包 / config 标题 / 首个菜单标题解析） */
  pluginTitle: string
  operations: ApiDocBrief[]
}

export interface ApiDocTree {
  groups: ApiDocGroup[]
  total: number
}

/** 枚举选项：value 与库表一致（数字列就是数字），label 取自列注释 */
export interface ApiDocEnumOption {
  value: string | number
  label: string
}

/** 参数来源：query 从 URL 取，body 走 JSON 请求体 */
export interface ApiDocParam {
  name: string
  in: 'query' | 'body'
  type: 'string' | 'integer' | 'number' | 'boolean'
  required: boolean
  /** 可选值（库表 CHECK 约束 + 列注释推断）；空/缺省表示是自由文本 */
  enum?: ApiDocEnumOption[]
  /** 列注释（字段含义） */
  description?: string
}

/** 返回字段（按库表结构推断） */
export interface ApiDocField {
  name: string
  type: 'string' | 'integer' | 'number' | 'boolean'
  comment: string
  enum: ApiDocEnumOption[]
}

export interface ApiDocResponse {
  /** 推断出的主表；null 表示推断不出来（聚合接口、公开接口等） */
  table: string | null
  /** true = 字段来自库表结构推断，不一定等于接口实际返回的列 */
  inferred: boolean
  fields: ApiDocField[]
}

export interface ApiDocDetail extends ApiDocBrief {
  controller: string
  action: string
  params: ApiDocParam[]
  /** true = 方法把整个请求包透传给 Logic，列出的参数由业务层源码推断，可能不全 */
  unbounded: boolean
  /** 推断出的主表（仅 GET 有意义） */
  table: string | null
  response: ApiDocResponse
}

export interface ApiDocTryResult {
  status: number
  /** 耗时（毫秒），取整 */
  duration: number
  /** 原始响应体：可能是 JSON，也可能是纯文本错误页 */
  body: string
}

export function apiDocTree() {
  return http.get<ApiDocTree>('/apidoc')
}

export function apiDocDetail(id: string) {
  return http.get<ApiDocDetail>('/apidoc/detail', { id })
}

const baseURL = import.meta.env.VITE_API_BASE ?? '/api'

/**
 * Try it out：**真的发一次请求**。
 *
 * 刻意绕开项目封装的 `http` 实例：
 *   - 它会拆信封、消费错误码并弹 `ElMessage`，而调试场景要看到原始状态码与响应体；
 *   - 它的超时是 15s，调试一个慢接口时反而容易误判成失败。
 * 鉴权仍复用同一份 token，所以权限、租户上下文、审计日志都是真实链路。
 */
export async function apiDocTry(
  method: string,
  path: string,
  query: Record<string, unknown>,
  body: Record<string, unknown>,
): Promise<ApiDocTryResult> {
  const started = performance.now()
  try {
    const response = await axios.request({
      baseURL,
      url: path,
      method: method.toLowerCase(),
      params: query,
      data: Object.keys(body).length > 0 ? body : undefined,
      timeout: 30000,
      responseType: 'text',
      transformResponse: [(data: unknown) => data],
      headers: {
        Authorization: `Bearer ${getToken()}`,
        'Accept-Language': currentLocale.value,
        'Content-Type': 'application/json',
      },
      // 4xx/5xx 也是有效调试结果，交给下面统一格式化
      validateStatus: () => true,
    })

    return {
      status: response.status,
      duration: Math.round(performance.now() - started),
      body: formatBody(response.data),
    }
  } catch (error) {
    return {
      status: 0,
      duration: Math.round(performance.now() - started),
      body: error instanceof Error ? error.message : String(error),
    }
  }
}

/**
 * 取完整 OpenAPI 3.1 文档（原始 JSON 文本）。
 *
 * 同样绕开 `http`：这个端点刻意**不套信封**（外部工具要直接吃 spec），
 * 走 `http` 会被当成「响应格式非法」直接抛错。
 */
export async function apiDocSpec(server = '/api'): Promise<string> {
  const response = await axios.get(`${baseURL}/apidoc/spec`, {
    params: { server },
    responseType: 'text',
    transformResponse: [(data: unknown) => data],
    headers: { Authorization: `Bearer ${getToken()}` },
    timeout: 30000,
  })

  return typeof response.data === 'string' ? response.data : JSON.stringify(response.data, null, 2)
}

/** JSON 美化；非 JSON（如 502 的 HTML 错误页）原样返回 */
function formatBody(data: unknown): string {
  if (typeof data !== 'string') {
    return JSON.stringify(data, null, 2)
  }
  try {
    return JSON.stringify(JSON.parse(data), null, 2)
  } catch {
    return data
  }
}

/** 复制成可直接粘到终端的 cURL，省去手拼 header */
export function apiDocCurl(
  method: string,
  path: string,
  query: Record<string, unknown>,
  body: Record<string, unknown>,
): string {
  const search = new URLSearchParams()
  Object.entries(query).forEach(([key, value]) => {
    if (value !== '' && value !== undefined && value !== null) {
      search.append(key, String(value))
    }
  })
  const qs = search.toString()
  const url = `${baseURL}${path}${qs ? `?${qs}` : ''}`
  const lines = [
    `curl -X ${method.toUpperCase()} '${url}'`,
    `  -H 'Authorization: Bearer <token>'`,
    `  -H 'Content-Type: application/json'`,
  ]
  if (Object.keys(body).length > 0) {
    lines.push(`  -d '${JSON.stringify(body)}'`)
  }

  return lines.join(' \\\n')
}
