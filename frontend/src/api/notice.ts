import { http } from './request'
import type { PageResult } from './types'

export interface NoticeRow {
  id: number
  title: string
  /** 1 通知 · 2 公告 */
  type: number
  /** 1 普通 · 2 重要 */
  level: number
  content: string
  /** 1 已发布 · 0 草稿 */
  status: number
  /** 投放范围 0全部用户 1指定部门 2指定角色 3指定用户 */
  scope: number
  /** 定向投放目标 id（scope!=0 时有值） */
  target_ids: number[]
  publish_at: string | null
  expire_at: string | null
  read_count: number
  create_time: string
  /** 阅读侧返回：当前用户是否已读 */
  is_read?: boolean
}

export interface NoticeTargetOption {
  id: number
  name?: string
  children?: NoticeTargetOption[]
}

/** 投放候选：部门树 + 角色树（用户候选走 noticeUsers 懒加载） */
export interface NoticeOptions {
  depts: NoticeTargetOption[]
  roles: NoticeTargetOption[]
}

/** 用户候选行 */
export interface NoticeUserOption {
  id: number
  username: string
  nickname: string
  status: number
}

/** 回执明细行 */
export interface NoticeReportRow {
  user_id: number
  username: string
  nickname: string
  read_time: string
}

export interface NoticeReport {
  notice: { id: number; title: string; scope: number }
  /** 应读人数 */
  total: number
  /** 已读人数 */
  read: number
  /** 未读人数 */
  unread: number
  list: NoticeReportRow[]
}

// ---- 管理侧 ----

export function noticeList(params: Record<string, unknown>) {
  return http.get<PageResult<NoticeRow>>('/notice', params)
}

export function noticeRead(id: number) {
  return http.get<NoticeRow>('/notice/read', { id })
}

export function noticeSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/notice/save', data)
}

export function noticeUpdate(data: Record<string, unknown>) {
  return http.post<null>('/notice/update', data)
}

export function noticeDelete(id: number) {
  return http.post<null>('/notice/delete', { id })
}

/** 投放目标候选（部门树 + 角色树） */
export function noticeOptions() {
  return http.get<NoticeOptions>('/notice/options')
}

/** 投放目标-用户候选：按关键词模糊搜索；传 ids 按 id 精确回显 */
export function noticeUsers(params: { keyword?: string; ids?: number[] }) {
  return http.get<NoticeUserOption[]>('/notice/users', {
    keyword: params.keyword,
    ids: params.ids?.length ? params.ids.join(',') : undefined,
  })
}

/** 已读回执统计报表 */
export function noticeReport(params: { id: number; view?: 'read' | 'unread'; page?: number; limit?: number }) {
  return http.get<NoticeReport>('/notice/report', params)
}

// ---- 阅读侧（登录即可，只看自己的） ----

export function myNoticeList(params: Record<string, unknown>) {
  return http.get<PageResult<NoticeRow>>('/notice/my', params)
}

export function noticeUnreadCount() {
  return http.get<{ count: number }>('/notice/unread')
}

export function noticeMarkRead(id: number) {
  return http.post<null>('/notice/markRead', { id })
}

export function noticeMarkAllRead() {
  return http.post<{ count: number }>('/notice/markAllRead')
}
