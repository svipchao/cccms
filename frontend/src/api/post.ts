import { http, type ListRequestConfig } from './request'
import type { PageResult } from './types'

export function postList(params: Record<string, unknown>, config?: ListRequestConfig) {
  return http.get<PageResult>('/post', params, config)
}

export function postSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/post/save', data)
}

export function postUpdate(data: Record<string, unknown>) {
  return http.post<null>('/post/update', data)
}

export function postDelete(id: number) {
  return http.post<null>('/post/delete', { id })
}

/** 批量操作结果：越权 / 下挂用户的岗位会被跳过并回传 */
export interface PostBatchResult {
  affected: number
  skipped: number[]
}

export function postBatchStatus(ids: number[], status: number) {
  return http.post<PostBatchResult>('/post/batchStatus', { ids, status })
}

export function postBatchDelete(ids: number[]) {
  return http.post<PostBatchResult>('/post/batchDelete', { ids })
}
