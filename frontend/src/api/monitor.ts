import { http } from './request'

/** 单个路径的聚合行（窗口内，按请求量倒序） */
export interface MonitorPathRow {
  path: string
  total: number
  errors: number
  /** 平均耗时（毫秒） */
  avg_cost: number
  /** P95 耗时（毫秒） */
  p95: number
}

export interface MonitorPaths {
  window: number
  rows: MonitorPathRow[]
}

export interface MonitorStatusGroup {
  group: string
  count: number
}

export interface MonitorOverview {
  /** 滚动窗口秒数 */
  window: number
  scrape_at: number
  requests: number
  errors: number
  /** 百分比，后端已四舍五入到 2 位 */
  error_rate: number
  /** 分位耗时（毫秒），如 { '0.95': 12.3 } */
  quantiles: Record<string, number>
  online: number
  components: { mysql: number; redis: number }
  paths_top: MonitorPathRow[]
  status_codes: MonitorStatusGroup[]
  /** false 时请求量只统计写操作与登录（读接口不落库） */
  record_read: boolean
}

export interface MonitorTrend {
  minutes: number
  labels: string[]
  requests: number[]
  errors: number[]
  avg_cost: number[]
}

export function monitorOverview() {
  return http.get<MonitorOverview>('/monitor')
}

export function monitorPaths() {
  return http.get<MonitorPaths>('/monitor/paths')
}

export function monitorTrend(minutes: number) {
  return http.get<MonitorTrend>('/monitor/trend', { minutes })
}

/* ---- 服务器资源（F6）---- */

export interface ServerCpu {
  /** 当前占用率（%）；非 Linux 为 null */
  percent: number | null
  cores: number | null
  /** 1 / 5 / 15 分钟平均负载；Windows 为 null */
  load: number[] | null
}

export interface ServerMemory {
  total: number | null
  available: number | null
  used: number | null
  percent: number | null
}

export interface ServerDisk {
  total: number | null
  free: number | null
  used_percent: number | null
}

export interface ServerRedis {
  used_memory: number
  used_memory_peak: number
  connected_clients: number
  blocked_clients: number
  ops_per_second: number
  keyspace_hits: number
  keyspace_misses: number
  evicted_keys: number
  rejected_connections: number
  uptime_seconds: number
}

export interface ServerMysql {
  version: string
  threads_connected: number
  threads_running: number
  max_connections: number
  /** 连接数占 max_connections 的百分比 */
  connection_usage: number
  uptime_seconds: number
  questions: number
  slow_queries: number
  aborted_connects: number
  buffer_pool_bytes: number
}

export interface MonitorServer {
  time: string
  os: { name: string; release: string; arch: string; host: string }
  php: { version: string; sapi: string; memory_used: number; memory_limit: number; workers: number }
  cpu: ServerCpu
  memory: ServerMemory
  disk: ServerDisk
  processes: number | null
  uptime: number | null
  redis: ServerRedis | null
  mysql: ServerMysql | null
  thresholds: { cpu: number; memory: number; disk: number; connections: number }
  /** 后端按阈值算好的越线标记（前端只负责上色，不重复判断） */
  warn: { cpu: boolean; memory: boolean; disk: boolean; connections: boolean }
}

export function monitorServer() {
  return http.get<MonitorServer>('/monitor/server')
}
