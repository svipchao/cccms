import { http } from './request'

/** 个人中心：当前登录用户自己的资料（后端只认 token 里的身份，不接受传 id） */
export interface ProfileDetail {
  id: number
  username: string
  nickname: string
  avatar: string
  email: string
  phone: string
  status: number
  remark: string
  login_time: string | null
  login_ip: string
  create_time: string | null
  /** 角色（含继承来的祖先角色） */
  roles: Array<{ id: number; code: string; name: string }>
  /** 所属部门名 */
  depts: string[]
}

export function profileRead() {
  return http.get<ProfileDetail>('/profile')
}

/** 只能改 nickname / avatar / email / phone（后端白名单） */
export function profileUpdate(data: Record<string, unknown>) {
  return http.post<null>('/profile/update', data)
}

export function profileChangePassword(data: { old_password: string; new_password: string }) {
  return http.post<null>('/profile/password', data)
}

/** 我的一条登录会话（字段与「在线用户」一致，终端信息由 UA 派生） */
export interface MySession {
  jti: string
  ip: string
  ua: string
  /** 设备类型：电脑 / 移动端 / 平板 / 爬虫 / 未知 */
  device: string
  /** 操作系统 */
  os: string
  /** 浏览器 */
  browser: string
  login_at: string
  last_at: string
  /** 令牌过期时间 */
  expire_at: string
  /** 是否为当前设备（前端据此禁用注销按钮） */
  current: boolean
}

/** 我的登录设备（后端只返回当前用户的会话） */
export function profileSessions() {
  return http.get<MySession[]>('/profile/sessions')
}

/** 注销我的一条登录设备 */
export function profileRevokeSession(jti: string) {
  return http.post<null>('/profile/sessions/revoke', { jti })
}
