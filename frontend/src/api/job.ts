import { http } from './request'
import type { PageResult } from './types'

/**
 * 作业队列（F5）。
 *
 * 状态语义与后端 `JobQueue::STATUS_*` 一一对应：
 * 0 待执行 · 1 执行中 · 2 成功 · 3 失败待重试 · 4 死信。
 */
export interface JobRow {
  id: number
  /** 队列名（隔离不同业务，如 export） */
  queue: string
  /** 实现 JobHandler 的完整类名 */
  target: string
  /** 后端给的短类名，列表里展示用 */
  target_label: string
  /** 投递参数（已解码） */
  payload: Record<string, unknown>
  status: number
  /** 已尝试次数（认领时就 +1，进程被 kill 也算一次） */
  attempts: number
  max_attempts: number
  /** 最早可执行时间（延迟投递 / 退避重试） */
  available_at: string
  reserved_at: string | null
  finished_at: string | null
  /** 幂等键：未结束的作业里唯一，结束后释放 */
  unique_key: string | null
  last_error: string
  create_time: string
  update_time: string
}

export interface JobStats {
  pending: number
  running: number
  done: number
  retry: number
  dead: number
  total: number
  /** 消费者心跳距今秒数；null = 从未心跳（进程没起 / Redis 不可用） */
  heartbeat_age: number | null
  /** 现有队列名（筛选下拉用） */
  queues: string[]
}

export interface JobLogRow {
  id: number
  job_id: number
  /** 第几次尝试（与 `sys_job.attempts` 口径一致） */
  attempt: number
  /** 1 成功 · 0 失败 */
  status: number
  /** 耗时(ms) */
  cost: number
  output: string
  create_time: string
}

export interface JobLogsResult {
  job: JobRow
  list: JobLogRow[]
}

export function jobList(params: Record<string, unknown>) {
  return http.get<PageResult<JobRow>>('/job', params)
}

export function jobStats() {
  return http.get<JobStats>('/job/stats')
}

export function jobLogs(id: number) {
  return http.get<JobLogsResult>('/job/logs', { id })
}

export function jobRetry(id: number) {
  return http.post<null>('/job/retry', { id })
}

export function jobRemove(ids: number[]) {
  return http.post<{ deleted: number }>('/job/remove', { ids })
}
