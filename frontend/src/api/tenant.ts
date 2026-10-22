import { http } from './request'
import type { PageResult } from './types'
import type { SwitchTenantResult } from './auth'

/** 租户行（`is_platform` 为 true 时是平台租户的**虚拟**行，库里没有这条记录） */
export interface TenantRow {
  id: number
  name: string
  code: string
  /** 自定义域名：绑定后经该域名访问只能使用此租户；`null` 表示未绑定 */
  domain: string | null
  contact: string
  phone: string
  status: number
  expire_at: string | null
  remark: string
  create_time: string | null
  update_time: string | null
  is_platform: boolean
  user_count: number
}

/** 顶栏切换器的候选项（超管专属；非超管接口返回空数组） */
export interface TenantOption {
  id: number
  name: string
  code: string
  is_platform: boolean
  current: boolean
  expire_at?: string | null
}

export function tenantList(params: Record<string, unknown>) {
  return http.get<PageResult<TenantRow>>('/tenant', params)
}

export function tenantRead(id: number) {
  return http.get<TenantRow>('/tenant/read', { id })
}

export function tenantSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/tenant/save', data)
}

export function tenantUpdate(data: Record<string, unknown>) {
  return http.post<null>('/tenant/update', data)
}

export function tenantDelete(id: number) {
  return http.post<null>('/tenant/delete', { id })
}

/** 可切换的租户候选（超管；普通账号返回空数组） */
export function tenantOptions() {
  return http.get<TenantOption[]>('/auth/tenants')
}

/**
 * 切换生效租户。
 *
 * 后端会**重签一份带 `tid` 声明的令牌**并返回新的用户信息，前端必须替换 token
 * （`userStore.switchTenant` 负责），否则下一个请求仍会按旧租户解析。
 */
export function switchTenant(tenantId: number) {
  return http.post<SwitchTenantResult>('/auth/switchTenant', { tenant_id: tenantId })
}
