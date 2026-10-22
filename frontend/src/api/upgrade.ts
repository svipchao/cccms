import instance from './request'

/** 在线升级只允许本地开发环境，git fetch/pull 可能较慢，统一放宽超时 */
const SLOW = { timeout: 180000 }

export interface UpgradeStatus {
  version: string
  enabled: boolean
  reason: string
  branch: string
  commit: string
  commit_time: string | null
  latest_tag: string
  dirty: boolean
  behind: number
  ahead: number
  can_run: boolean
}

export interface UpgradeCheck {
  behind: number
  ahead: number
  latest_tag: string
  upstream: string
  commits: string[]
}

export interface UpgradeTag {
  name: string
  subject: string
  date: string | null
}

export interface UpgradeResult {
  output: string
  changed: boolean
  restart_required: boolean
}

export function upgradeStatus() {
  return instance.get('/upgrade', SLOW) as unknown as Promise<UpgradeStatus>
}

export function upgradeCheck() {
  return instance.get('/upgrade/check', SLOW) as unknown as Promise<UpgradeCheck>
}

export function upgradeTags() {
  return instance.get('/upgrade/tags', SLOW) as unknown as Promise<UpgradeTag[]>
}

export function upgradeInit() {
  return instance.post('/upgrade/init', undefined, SLOW) as unknown as Promise<UpgradeResult>
}

export function upgradeRun() {
  return instance.post('/upgrade/run', undefined, SLOW) as unknown as Promise<UpgradeResult>
}
