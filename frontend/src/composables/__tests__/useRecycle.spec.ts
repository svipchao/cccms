import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useRecycle } from '@/composables/useRecycle'
import { recycleDelete, recycleRestore } from '@/api/recycle'
import { ElMessage, ElMessageBox } from 'element-plus'

vi.mock('@/api/recycle', () => ({
  recycleRestore: vi.fn(),
  recycleDelete: vi.fn(),
}))

vi.mock('@/locales', () => ({
  t: (key: string) => key,
}))

vi.mock('element-plus', () => ({
  ElMessage: { success: vi.fn(), warning: vi.fn() },
  ElMessageBox: { confirm: vi.fn() },
}))

const confirmMock = vi.mocked(ElMessageBox.confirm)
const restoreMock = vi.mocked(recycleRestore)
const deleteMock = vi.mocked(recycleDelete)

/** 每个用例都重新建一份，避免 ref 状态互相污染 */
function create(ids?: number[]) {
  const reload = vi.fn()
  const clear = vi.fn()
  const api = useRecycle('user', { reload, clear, ids: ids ? () => ids : undefined })

  return { ...api, reload, clear }
}

describe('useRecycle', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    confirmMock.mockResolvedValue('confirm' as never)
  })

  it('切换回收站：取反 + 清勾选 + 重新取数', () => {
    const { recycle, toggle, reload, clear } = create()

    toggle()
    expect(recycle.value).toBe(true)
    expect(clear).toHaveBeenCalledTimes(1)
    expect(reload).toHaveBeenCalledTimes(1)

    toggle()
    expect(recycle.value).toBe(false)
    expect(reload).toHaveBeenCalledTimes(2)
  })

  it('单行还原：只还原该行，成功后清勾选并刷新', async () => {
    restoreMock.mockResolvedValue({ restored: 1 } as never)
    const { onRestore, reload, clear } = create()

    await onRestore({ id: 7 })

    expect(restoreMock).toHaveBeenCalledWith({ type: 'user', ids: [7] })
    expect(ElMessage.success).toHaveBeenCalled()
    expect(clear).toHaveBeenCalled()
    expect(reload).toHaveBeenCalled()
  })

  it('批量还原：没有勾选时直接返回，不弹确认也不调接口', async () => {
    const { onRestore } = create([])

    await onRestore()

    expect(confirmMock).not.toHaveBeenCalled()
    expect(restoreMock).not.toHaveBeenCalled()
  })

  it('取消确认时什么都不做（不能把「取消」当失败）', async () => {
    confirmMock.mockRejectedValue(new Error('cancel'))
    const { onRestore, reload } = create()

    await onRestore({ id: 3 })

    expect(restoreMock).not.toHaveBeenCalled()
    expect(reload).not.toHaveBeenCalled()
  })

  it('非法 id（0 / NaN）当作没有目标，避免误操作整批', async () => {
    const { onForceDelete } = create()

    await onForceDelete({ id: 0 })

    expect(deleteMock).not.toHaveBeenCalled()
  })

  it('彻底删除：按勾选批量删除并提示条数', async () => {
    deleteMock.mockResolvedValue({ deleted: 2 } as never)
    const { onForceDelete, reload } = create([1, 2])

    await onForceDelete()

    expect(deleteMock).toHaveBeenCalledWith({ type: 'user', ids: [1, 2] })
    expect(ElMessage.success).toHaveBeenCalled()
    expect(reload).toHaveBeenCalled()
  })
})
