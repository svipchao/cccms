import { beforeEach, describe, expect, it } from 'vitest'
import {
  acquireTraceContext,
  currentActionId,
  currentSessionId,
  newAction,
  newActionId,
  newSessionId,
  resetTrace,
} from '@/utils/trace'

describe('请求标识（session / action）', () => {
  beforeEach(() => {
    resetTrace()
  })

  it('ID 格式与后端校验一致（前缀 + yymmddHHmmss + 8 位十六进制）', () => {
    const date = new Date(2026, 8, 18, 14, 30, 12)

    expect(newActionId(date)).toMatch(/^a260918143012-[0-9a-f]{8}$/)
    expect(newSessionId(date)).toMatch(/^s260918143012-[0-9a-f]{8}$/)
    expect(newActionId(date).length).toBeLessThanOrEqual(32)
  })

  it('同一页面里的读请求共用一个操作', () => {
    const first = acquireTraceContext('GET')
    const second = acquireTraceContext('GET')

    expect(second.actionId).toBe(first.actionId)
    expect(second.sessionId).toBe(first.sessionId)
  })

  it('写请求开启新操作，随后的读请求（保存后刷新列表）跟随它', () => {
    const beforeSave = acquireTraceContext('GET')
    const saving = acquireTraceContext('POST')
    const reload = acquireTraceContext('GET')

    expect(saving.actionId).not.toBe(beforeSave.actionId)
    expect(reload.actionId).toBe(saving.actionId)
    // 换操作不换会话
    expect(saving.sessionId).toBe(beforeSave.sessionId)
  })

  it('连续两次写请求是两次操作', () => {
    const firstDelete = acquireTraceContext('DELETE')
    const secondDelete = acquireTraceContext('DELETE')

    expect(secondDelete.actionId).not.toBe(firstDelete.actionId)
  })

  it('路由切换只换操作，不换会话；刷新（resetTrace）两者都换', () => {
    const actionBeforeRoute = currentActionId()
    const sessionBeforeRoute = currentSessionId()

    newAction()
    expect(currentActionId()).not.toBe(actionBeforeRoute)
    expect(currentSessionId()).toBe(sessionBeforeRoute)

    resetTrace()
    expect(currentSessionId()).not.toBe(sessionBeforeRoute)
  })
})
