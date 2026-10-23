import { http, type ListRequestConfig } from './request'
import type { PageResult } from './types'

export function userList(params: Record<string, unknown>, config?: ListRequestConfig) {
  return http.get<PageResult>('/user', params, config)
}

export function userRead(id: number) {
  return http.get<Record<string, unknown>>('/user/read', { id })
}

export function userSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/user/save', data)
}

export function userUpdate(data: Record<string, unknown>) {
  return http.post<null>('/user/update', data)
}

export function userDelete(id: number) {
  return http.post<null>('/user/delete', { id })
}

export function userResetPassword(data: { id: number; password: string }) {
  return http.post<null>('/user/resetPassword', data)
}

/** 批量操作结果：越权 / 受保护的 id 会被跳过并回传 */
export interface BatchResult {
  affected: number
  skipped: number[]
}

/** 批量启用 / 禁用（禁用后对方下一次请求即 401） */
export function userBatchStatus(ids: number[], status: number) {
  return http.post<BatchResult>('/user/batchStatus', { ids, status })
}

export function userBatchDelete(ids: number[]) {
  return http.post<BatchResult>('/user/batchDelete', { ids })
}

/** 批量分配角色 / 部门 / 岗位：传了哪个键就整体替换哪个关联，未传的保持原样 */
export function userBatchAssign(data: {
  ids: number[]
  role_ids?: number[]
  dept_ids?: number[]
  post_ids?: number[]
}) {
  return http.post<BatchResult>('/user/batchAssign', data)
}
