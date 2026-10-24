import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({ disconnectRealtime: vi.fn() }))

// 只关心「有没有断开推送连接」：真开 EventSource 需要后端，单测里换成间谍
vi.mock('@/composables/useRealtime', () => ({ disconnectRealtime: mocks.disconnectRealtime }))

import { resetAfterLogout } from '@/router'
import { useExportTaskStore } from '@/stores/exportTask'
import { useNoticeStore } from '@/stores/notice'

/**
 * 登出清理。
 *
 * 重点守一件事：**推送连接必须断开**。`connectRealtime()` 是单例（已有连接直接返回），
 * 不断开的话换账号后仍连在旧账号的 userId 频道上 —— 新账号收不到自己的角标，
 * 旧账号被「整用户踢下线」时还会把新会话一起登出。
 */
describe('resetAfterLogout', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.disconnectRealtime.mockClear()
  })

  it('断开实时推送连接（单例连接不会自己跟着令牌走）', () => {
    resetAfterLogout()

    expect(mocks.disconnectRealtime).toHaveBeenCalledTimes(1)
  })

  it('清空账号级 store：未读角标与导出任务都属于上一个账号', () => {
    const notice = useNoticeStore()
    const exportTask = useExportTaskStore()
    notice.unread = 3
    exportTask.unfinished = 2

    resetAfterLogout()

    expect(notice.unread).toBe(0)
    expect(exportTask.unfinished).toBe(0)
  })
})
