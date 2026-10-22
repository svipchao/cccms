import { http } from './request'
import type { PageResult } from './types'

export interface CrontabRow {
  id: number
  name: string
  /** 任务分组（仅用于归类与筛选） */
  group_name: string
  cron: string
  target: string
  params: unknown
  status: number
  /** skip = 上次未结束则跳过 · allow = 允许并发 */
  overlap: 'skip' | 'allow'
  /** 超时秒数，0 = 不限 */
  timeout: number
  retry_times: number
  retry_interval: number
  /** 运行时：是否运行中 */
  running?: number
  remark: string
  last_run_time: string | null
  next_run_time: string | null
}

export interface CrontabLogRow {
  id: number
  run_time: string
  /** 1 成功 · 0 失败 · 2 跳过 · 3 超时释放 */
  status: number
  /** cron / retry / manual / timeout */
  source: string
  cost: number
  output: string
}

export interface TaskTarget {
  value: string
  label: string
}

export interface RunResult {
  status: number
  output: string
  cost: number
}

export function crontabList(params: Record<string, unknown>) {
  return http.get<PageResult<CrontabRow>>('/crontab', params)
}

export function crontabTargets() {
  return http.get<TaskTarget[]>('/crontab/targets')
}

export function crontabSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/crontab/save', data)
}

export function crontabUpdate(data: Record<string, unknown>) {
  return http.post<null>('/crontab/update', data)
}

export function crontabDelete(id: number) {
  return http.post<null>('/crontab/delete', { id })
}

export function crontabRun(id: number) {
  return http.post<RunResult>('/crontab/run', { id })
}

export function crontabLogs(params: Record<string, unknown>) {
  return http.get<PageResult<CrontabLogRow>>('/crontab/logs', params)
}
