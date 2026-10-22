import { http } from './request'
import type { PageResult } from './types'

export interface OnlineSession {
  /** 会话 ID（令牌 jti），强制下线的目标 */
  jti: string
  user_id: number
  username: string
  nickname: string
  ip: string
  ua: string
  /** 设备类型：电脑 / 移动端 / 平板 / 爬虫 / 未知（由 UA 派生） */
  device: string
  /** 操作系统：Windows / macOS / iOS / Android / HarmonyOS / Linux，未知为空 */
  os: string
  /** 浏览器：Chrome / Edge / Firefox / Safari / Opera / IE / 微信 / 其他 */
  browser: string
  login_at: string
  last_at: string
  /** 令牌过期时间 */
  expire_at: string
}

export function onlineList(params: Record<string, unknown>) {
  return http.get<PageResult<OnlineSession>>('/online', params)
}

/** 强制下线单个会话 */
export function onlineKick(jti: string) {
  return http.post<null>('/online/kick', { jti })
}

/** 强制某用户的所有会话下线 */
export function onlineKickUser(userId: number) {
  return http.post<{ sessions: number }>('/online/kickUser', { id: userId })
}
