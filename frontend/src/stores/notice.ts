import { defineStore } from 'pinia'
import { ref } from 'vue'
import { myNoticeList, noticeMarkAllRead, noticeMarkRead, noticeUnreadCount, type NoticeRow } from '@/api/notice'

/**
 * 通知公告（阅读侧）。
 *
 * 只服务于顶栏的「我的消息」抽屉与未读角标；管理列表在页面里直接用 `api/notice`。
 * 未读数是全局状态（顶栏常驻），所以放 store 而不是页面里。
 */
export const useNoticeStore = defineStore('notice', () => {
  const unread = ref(0)
  const items = ref<NoticeRow[]>([])
  const total = ref(0)
  const loading = ref(false)

  /** 刷新未读角标；失败静默（角标不是关键路径，未登录时也会走到这里） */
  async function refreshUnread(): Promise<void> {
    try {
      const res = await noticeUnreadCount()
      unread.value = Number(res?.count ?? 0)
    } catch {
      unread.value = 0
    }
  }

  async function load(params: Record<string, unknown> = {}): Promise<void> {
    loading.value = true
    try {
      const res = await myNoticeList(params)
      items.value = (res.list ?? []) as NoticeRow[]
      total.value = res.total ?? 0
    } catch {
      // 失败提示由请求拦截器统一处理；这里兜住异常，避免调用方 `void load()`
      // 产生 unhandled rejection（保留上一次的列表，用户可直接重试）
    } finally {
      loading.value = false
    }
  }

  async function markRead(id: number): Promise<void> {
    if (items.value.find((item) => item.id === id)?.is_read) {
      return
    }
    await noticeMarkRead(id)
    items.value = items.value.map((item) => (item.id === id ? { ...item, is_read: true } : item))
    await refreshUnread()
  }

  async function markAllRead(): Promise<void> {
    await noticeMarkAllRead()
    items.value = items.value.map((item) => ({ ...item, is_read: true }))
    unread.value = 0
  }

  function reset(): void {
    unread.value = 0
    items.value = []
    total.value = 0
  }

  return { unread, items, total, loading, refreshUnread, load, markRead, markAllRead, reset }
})
