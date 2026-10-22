import { http } from './request'

/** 签发出来的令牌（登录与「切换租户」重签的形状一致） */
export interface TokenResult {
  token: string
  expires_in: number
  expires_at: number
}

export interface LoginResult {
  token: TokenResult
  user: Record<string, unknown>
}

export interface Profile {
  id: number
  username: string
  nickname: string
  avatar: string
  super_admin: boolean
  roles: Array<{ id: number; code: string; name: string }>
  permissions: string[]
  /** 当前**生效**租户（超管切换后 ≠ home_tenant_id） */
  tenant_id?: number
  /** 账号归属租户（`sys_user.tenant_id`，不随切换变化） */
  home_tenant_id?: number
  /** 是否处于「切换后的租户」（前端据此显示返回平台的入口） */
  tenant_switched?: boolean
}

/** 「切换租户」的响应：新令牌 + 新上下文下的用户信息 */
export interface SwitchTenantResult {
  token: TokenResult
  user: Profile
}

/** 图形验证码（后端未实现时 data 为 null，前端据此隐藏该输入项） */
export interface CaptchaResult {
  captcha_id: string
  image: string
}

export interface LoginParams {
  username: string
  password: string
  captcha?: string
  captcha_id?: string
}

export function login(data: LoginParams) {
  return http.post<LoginResult>('/auth/login', data)
}

export function logout() {
  return http.post<null>('/auth/logout')
}

export function me() {
  return http.get<Profile>('/auth/me')
}

export function captcha() {
  return http.get<CaptchaResult | null>('/auth/captcha')
}

export type ResetChannel = 'email' | 'sms'

export interface SendResetCodeParams {
  account: string
  channel: ResetChannel
}

export interface ResetPasswordParams extends SendResetCodeParams {
  code: string
  password: string
}

/** 找回密码：发送验证码（响应统一，不区分账号是否存在） */
export function sendResetCode(data: SendResetCodeParams) {
  return http.post<null>('/auth/password/sendCode', data)
}

/** 找回密码：用验证码重置口令（成功后旧登录全部失效） */
export function resetPassword(data: ResetPasswordParams) {
  return http.post<null>('/auth/password/reset', data)
}

export function ping() {
  return http.get<{ pong: boolean; time: string }>('/ping')
}
