import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { recycleDelete, recycleRestore } from '@/api/recycle'
import { t } from '@/locales'

/** 表格行：回收站只需要它的 id，其余字段不管 */
type RecycleRow = Record<string, any>

export interface RecycleOptions {
  /** 切换模式、以及操作完成后重新加载列表 */
  reload: () => void
  /** 当前勾选行的 id（批量操作用）；不传则只支持单行操作 */
  ids?: () => number[]
  /** 清空勾选 */
  clear?: () => void
}

/**
 * 模块页的「回收站」开关 + 还原 / 彻底删除。
 *
 * 回收站**既不是独立页面，也不是独立接口**：点一下开关，同一张表改成查已删数据
 * （列表接口带 `trashed=1`），操作列换成「还原 / 彻底删除」，列完全不用动。
 *
 * ⚠️ 必须在 `useTable` **之前**调用：列表闭包在 setup 阶段就会执行一次，
 * 那时 `recycle` 必须已经初始化（否则会撞上 const 的暂时性死区）。
 * `options` 里的回调都是延迟执行的，所以可以安全地引用后面才声明的 `search` / `selection`。
 *
 * 三处用法：
 * ```ts
 * const { recycle, toggle, onRestore, onForceDelete } = useRecycle('user', {
 *   reload: () => search(),
 *   ids: () => selection.value.map((item) => item.id), // 有勾选列才需要
 *   clear: () => { selection.value = [] },
 * })
 * ```
 * 1. 列表 api：`userList({ ...params, trashed: recycle.value ? 1 : 0 })`
 * 2. 表格：`<ArtTable :recycle="recycle" @restore="onRestore" @force-delete="onForceDelete" ...>`
 * 3. 工具栏：`<RecycleToggle :active="recycle" label="用户" @toggle="toggle" />`
 *
 * @param type 后端 RecycleLogic 注册表里的类型（user / role / dict_type …）
 */
export function useRecycle(type: string, options: RecycleOptions) {
  const recycle = ref(false)

  /** 目标 id：传了 row 就操作这一行，否则操作当前勾选（批量） */
  function targetIds(row?: RecycleRow): number[] {
    if (!row) {
      return options.ids?.() ?? []
    }
    const id = Number(row.id)
    return Number.isFinite(id) && id > 0 ? [id] : []
  }

  /** 切换数据源：正反两个方向都要重新取数，并清掉上一批勾选 */
  function toggle(): void {
    recycle.value = !recycle.value
    options.clear?.()
    options.reload()
  }

  async function onRestore(row?: RecycleRow): Promise<void> {
    const ids = targetIds(row)
    if (ids.length === 0) {
      return
    }
    try {
      await ElMessageBox.confirm(t('core.table.restoreConfirm', { count: ids.length }), t('core.table.restore'), {
        type: 'warning',
      })
    } catch {
      return
    }
    const res = await recycleRestore({ type, ids })
    ElMessage.success(t('core.table.restoreDone', { count: res?.restored ?? ids.length }))
    options.clear?.()
    options.reload()
  }

  async function onForceDelete(row?: RecycleRow): Promise<void> {
    const ids = targetIds(row)
    if (ids.length === 0) {
      return
    }
    try {
      await ElMessageBox.confirm(
        t('core.table.forceDeleteConfirm', { count: ids.length }),
        t('core.table.forceDelete'),
        { type: 'warning', confirmButtonText: t('core.table.forceDelete') },
      )
    } catch {
      return
    }
    const res = await recycleDelete({ type, ids })
    ElMessage.success(t('core.table.forceDeleteDone', { count: res?.deleted ?? ids.length }))
    options.clear?.()
    options.reload()
  }

  return { recycle, toggle, onRestore, onForceDelete }
}
