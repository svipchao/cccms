import { downloadFile, http } from './request'
import type { PageResult } from './types'

/** 备份类型：手工 / 定时 / 还原前自动 */
export type BackupKind = 'manual' | 'cron' | 'pre_restore'

/** 0 进行中 / 1 完成 / 2 失败 */
export type BackupStatus = 0 | 1 | 2

export interface BackupRow {
  id: number
  file_name: string
  /** 相对 runtime 的路径（不进 public） */
  file_path: string
  size: number
  /** sha256，还原前校验 */
  checksum: string
  /** mysqldump / pdo（pdo 无一致性快照）/ '' 未完成 */
  engine: '' | 'mysqldump' | 'pdo'
  table_count: number
  /** 仅 pdo 精确统计；mysqldump 为 0 */
  row_count: number
  kind: BackupKind
  status: BackupStatus
  /** 耗时(ms) */
  duration: number
  error: string
  user_id: number
  create_time: string
  update_time: string
}

export interface BackupCreateResult {
  id: number
  file_name: string
  size: number
  engine: string
  table_count: number
  row_count: number
  duration: number
}

export interface BackupTablesResult {
  /** 当前库名（还原确认串要手输它） */
  database: string
  tables: string[]
}

export interface BackupRestoreResult {
  tables: number
  statements: number
  /** 还原前自动备份的记录 id */
  pre_backup_id: number
}

export function backupList(params: Record<string, unknown>) {
  return http.get<PageResult<BackupRow>>('/backup', params)
}

/** 立即备份（**同步**执行，大库请用定时任务） */
export function backupCreate() {
  return http.post<BackupCreateResult>('/backup/create')
}

export function backupDelete(ids: number[]) {
  return http.post<{ deleted: number }>('/backup/delete', { ids })
}

export function backupDownload(id: number) {
  return downloadFile('/backup/download', { id }, 'backup.sql.gz')
}

/** 归档里的表清单 + 当前库名 */
export function backupTables(id: number) {
  return http.get<BackupTablesResult>('/backup/tables', { id })
}

/** 还原（破坏性）：tables 为空表示全量，confirm 必须是当前库名 */
export function backupRestore(payload: { id: number; tables: string[]; confirm: string }) {
  return http.post<BackupRestoreResult>('/backup/restore', payload)
}
