import instance from './request'

/** 在线升级只允许本地开发环境，git fetch/pull 可能较慢，统一放宽超时 */
const SLOW = { timeout: 180000 }

/** 仓库已配置的 git 远端（url 已由后端去掉 userinfo / 查询串） */
export interface UpgradeRemote {
  name: string
  url: string
  /** 未显式选择时后端会用的远端 */
  is_default: boolean
}

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
  /** 比对用的上游引用（如 cccms/main），未设置跟踪分支时由远端兜底 */
  upstream: string
  can_run: boolean
  /** 页面下拉可选的同步来源；无远端或读取失败时为空 */
  remotes: UpgradeRemote[]
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

/** remote 缺省时后端按 CCCMS_UPGRADE_REMOTE / 唯一远端决定 */
function remoteConfig(remote?: string) {
  return { ...SLOW, params: remote ? { remote } : undefined }
}

export function upgradeCheck(remote?: string) {
  return instance.get('/upgrade/check', remoteConfig(remote)) as unknown as Promise<UpgradeCheck>
}

export function upgradeTags() {
  return instance.get('/upgrade/tags', SLOW) as unknown as Promise<UpgradeTag[]>
}

export function upgradeInit(remote?: string) {
  return instance.post('/upgrade/init', undefined, remoteConfig(remote)) as unknown as Promise<UpgradeResult>
}

export function upgradeRun(remote?: string) {
  return instance.post('/upgrade/run', undefined, remoteConfig(remote)) as unknown as Promise<UpgradeResult>
}
