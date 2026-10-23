import { http } from './request'

/**
 * 在线命令终端（F2）。
 *
 * 命令表由后端 `config/terminal.php` 登记并翻译后下发：前端只负责渲染表单，
 * **不知道也不允许拼命令行** —— 提交的永远是 `key` + 参数值。
 */

/** 1 普通 / 2 危险（删数据、归档，需二次确认）/ 3 高危（改库结构） */
export type TerminalRisk = 1 | 2 | 3

export interface TerminalParam {
  name: string
  /** 已翻译的参数名 */
  label: string
  type: 'int' | 'bool' | 'text'
  required: boolean
  min: number | null
  max: number | null
  pattern: string | null
  max_length: number | null
}

export interface TerminalCommand {
  key: string
  /** 已翻译的命令名 */
  label: string
  description: string
  /** sync / check / data / other */
  group: string
  risk: TerminalRisk
  /** argv 模板（`{php}` / `{root}` 未展开），仅用于展示「实际会跑什么」 */
  argv: string[]
  params: TerminalParam[]
}

export interface TerminalCatalog {
  enabled: boolean
  /** 单条命令超时（秒） */
  timeout: number
  /** 输出上限（字节） */
  output_limit: number
  commands: TerminalCommand[]
}

/** 作业状态：0 待执行 / 1 执行中 / 2 成功 / 3 失败待重试 / 4 死信 */
export type TerminalJobStatus = 0 | 1 | 2 | 3 | 4

export interface TerminalJobPayload {
  key?: string
  params?: Record<string, string | number | boolean>
  user_id?: number
}

export interface TerminalJobRow {
  id: number
  queue: string
  status: TerminalJobStatus
  attempts: number
  max_attempts: number
  last_error: string
  payload: TerminalJobPayload
  create_time: string
  finished_at: string | null
}

export interface TerminalLogRow {
  id: number
  job_id: number
  attempt: number
  /** 该次尝试结束时的作业状态 */
  status: number
  output: string
  duration: number
  create_time: string
}

export function terminalCatalog() {
  return http.get<TerminalCatalog>('/terminal')
}

/** 最近 20 条终端作业（只含 terminal 队列） */
export function terminalHistory() {
  return http.get<{ list: TerminalJobRow[] }>('/terminal/history')
}

/** 单条作业 + 每次尝试的输出（轮询用） */
export function terminalLogs(jobId: number) {
  return http.get<{ job: TerminalJobRow; list: TerminalLogRow[] }>('/terminal/logs', { job_id: jobId })
}

/**
 * 投递一条命令。
 *
 * `risk >= 2` 时 `confirm` 必须等于命令 key：前端弹窗只是防止误点，
 * 真正的闸门在后端（`TerminalLogic::run()`）。
 */
export function terminalRun(payload: {
  key: string
  params: Record<string, string | number | boolean>
  confirm?: string
}) {
  return http.post<{ job_id: number }>('/terminal/run', payload)
}
