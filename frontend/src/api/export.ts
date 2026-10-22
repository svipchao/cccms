import { downloadFile, http } from './request'
import type { PageResult } from './types'

/** 导出任务状态：0待处理 1处理中 2完成 3失败 4已过期 */
export type ExportTaskStatus = 0 | 1 | 2 | 3 | 4

export interface ExportTaskRow {
  id: number
  type: string
  status: ExportTaskStatus
  total_rows: number
  file_name: string
  error: string
  create_time: string
}

interface RawExportTask {
  id: number
  type: string
  status: ExportTaskStatus
  row_count: number
  error: string | null
  create_time: string
}

/** 我的导出任务列表（最新在前） */
export async function exportTaskList(): Promise<ExportTaskRow[]> {
  const page = await http.get<PageResult<RawExportTask>>('/export/task/list')
  return page.list.map((item) => ({
    id: item.id,
    type: item.type,
    status: item.status,
    total_rows: item.row_count,
    file_name: '',
    error: item.error ?? '',
    create_time: item.create_time,
  }))
}

/** 下载导出归档文件 */
export function exportTaskDownload(id: number) {
  return downloadFile('/export/task/download', { id })
}
