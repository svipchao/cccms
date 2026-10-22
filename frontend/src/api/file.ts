import { http } from './request'
import type { PageResult } from './types'

export interface FileRow {
  id: number
  original_name: string
  name: string
  path: string
  url: string
  ext: string
  mime: string
  size: number
  /** 所属分类（0 = 未分类） */
  category_id?: number
  /** 后台补的分类名，避免前端再查一次 */
  category_name?: string
  create_time: string
  /** 是否图片（由后台 upload.image_ext 配置推导） */
  is_image?: boolean
  /** 是否 PDF（后端按扩展名推导，前端据此用 iframe 内嵌预览） */
  is_pdf?: boolean
}

export function fileList(params: Record<string, unknown>) {
  return http.get<PageResult<FileRow>>('/file', params)
}

export function fileDelete(id: number) {
  return http.post<null>('/file/delete', { id })
}

/** 批量移动到分类（categoryId <= 0 表示移出分类） */
export function fileMove(ids: number[], categoryId: number) {
  return http.post<{ count: number }>('/file/move', { ids, category_id: categoryId })
}

// ?? 而非 ||：空串是合法值（生产无前缀），用 || 会退化成 //file/upload
export const FILE_UPLOAD_URL = `${import.meta.env.VITE_API_BASE ?? '/api'}/file/upload`

/** 单请求直传（≤ 单文件上限时使用） */
export function fileUpload(form: FormData) {
  return http.post<FileRow>('/file/upload', form)
}

/** 分片上传第一步的返回 */
export interface UploadInitResult {
  instant: boolean
  /** 秒传命中：直接复用已有记录 */
  file?: FileRow
  /** 非秒传：会话与分片参数 */
  upload_id?: string
  chunk_size?: number
  total_chunks?: number
  /** 已上传的分片序号（断点续传依据） */
  received?: number[]
}

export function fileUploadInit(data: { filename: string; size: number; sha1?: string; category_id?: number }) {
  return http.post<UploadInitResult>('/file/upload/init', data)
}

/** 上传一片（幂等，可重传；带文件名让后端把它当文件而不是普通字段） */
export function fileUploadChunk(uploadId: string, index: number, chunk: Blob, fileName: string) {
  const form = new FormData()
  form.append('upload_id', uploadId)
  form.append('index', String(index))
  form.append('file', chunk, fileName)

  return http.post<{ size: number }>('/file/upload/chunk', form)
}

export function fileUploadComplete(uploadId: string) {
  return http.post<{ instant: boolean; file: FileRow }>('/file/upload/complete', { upload_id: uploadId })
}
