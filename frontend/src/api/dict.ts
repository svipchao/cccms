import { http, type ListRequestConfig } from './request'
import type { PageResult } from './types'

export function dictTypeList(params: Record<string, unknown>, config?: ListRequestConfig) {
  return http.get<PageResult>('/dict', params, config)
}

export function dictTypeSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/dict/save', data)
}

export function dictTypeUpdate(data: Record<string, unknown>) {
  return http.post<null>('/dict/update', data)
}

export function dictTypeDelete(id: number) {
  return http.post<null>('/dict/delete', { id })
}

export interface DictDataRow {
  id: number
  type_id: number
  label: string
  value: string
  sort: number
  status: number
  remark?: string
  [key: string]: unknown
}

/** 字典数据；trashed=true 取回收站（只看该类型下已删的数据） */
export function dictDataList(typeId: number, trashed = false) {
  return http.get<DictDataRow[]>('/dict/data', {
    type_id: typeId,
    trashed: trashed ? 1 : 0,
  })
}

export function dictDataSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/dict/saveData', data)
}

export function dictDataUpdate(data: Record<string, unknown>) {
  return http.post<null>('/dict/updateData', data)
}

export function dictDataDelete(id: number) {
  return http.post<null>('/dict/deleteData', { id })
}

/** 批量操作结果：不可见 / 已删除的 id 会被跳过并回传 */
export interface DictBatchResult {
  affected: number
  skipped: number[]
}

export function dictTypeBatchStatus(ids: number[], status: number) {
  return http.post<DictBatchResult>('/dict/batchStatus', { ids, status })
}

export function dictTypeBatchDelete(ids: number[]) {
  return http.post<DictBatchResult>('/dict/batchDelete', { ids })
}

export function dictDataBatchStatus(ids: number[], status: number) {
  return http.post<DictBatchResult>('/dict/batchStatusData', { ids, status })
}

export function dictDataBatchDelete(ids: number[]) {
  return http.post<DictBatchResult>('/dict/batchDeleteData', { ids })
}
