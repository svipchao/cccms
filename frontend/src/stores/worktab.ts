import { defineStore } from 'pinia'
import { computed, nextTick, ref } from 'vue'

export interface TabItem {
  /** 路由完整路径，作为唯一 key */
  path: string
  /** 路由 name，同时是页面组件的 name（keep-alive include 依赖它） */
  name: string
  title: string
  icon?: string
  /** 固定标签：不可关闭，始终排在最前 */
  pinned: boolean
}

/** 首页标签：固定存在，不受权限控制 */
export const HOME_PATH = '/dashboard'

/** 刷新后重新缓存的延迟（等 keep-alive 完成裁剪） */
const REFRESH_DELAY = 60

function createHomeTab(): TabItem {
  // title 存 i18n key 而非成品文案：标签栏渲染时经 translateTitle() 翻译，
  // 这样切换语言无需重建标签页。
  return { path: HOME_PATH, name: 'dashboard', title: 'core.route.dashboard', icon: 'icon-home', pinned: true }
}

export const useWorktabStore = defineStore('worktab', () => {
  const tabs = ref<TabItem[]>([createHomeTab()])
  const active = ref<string>(HOME_PATH)

  /** 临时移出缓存的组件名（用于「刷新当前页」） */
  const excluded = ref<string[]>([])

  /** 各标签的刷新序号：自增后作为组件 key，强制渲染器卸载旧实例并挂载新实例 */
  const stamps = ref<Record<string, number>>({})

  /**
   * keep-alive 的 include 名单 = 已打开标签的组件名。
   * 关闭标签即从名单移除，实例才会真正销毁。
   */
  const cached = computed<string[]>(() => tabs.value.map((t) => t.name).filter((n) => !excluded.value.includes(n)))

  function findIndex(path: string): number {
    return tabs.value.findIndex((t) => t.path === path)
  }

  function setActive(path: string): void {
    active.value = path
  }

  /** 打开（或激活）标签；已存在时只更新文案，保证菜单改名后能跟随 */
  function addTab(tab: Omit<TabItem, 'pinned'> & { pinned?: boolean }): void {
    const index = findIndex(tab.path)
    if (index >= 0) {
      tabs.value[index] = { ...tabs.value[index], title: tab.title, icon: tab.icon }
      return
    }
    const item: TabItem = { ...tab, pinned: tab.pinned ?? false }
    if (item.pinned) {
      tabs.value.unshift(item)
    } else {
      tabs.value.push(item)
    }
  }

  /** @returns 关闭后应跳转的路径；关闭的不是当前标签时返回 null */
  function closeTab(path: string): string | null {
    const index = findIndex(path)
    if (index < 0 || tabs.value[index].pinned) {
      return null
    }
    tabs.value.splice(index, 1)

    if (active.value !== path) {
      return null
    }
    const next = tabs.value[index] ?? tabs.value[index - 1] ?? tabs.value[0]
    return next ? next.path : HOME_PATH
  }

  /** 关闭其他（固定标签与指定标签保留） */
  function closeOthers(path: string): void {
    tabs.value = tabs.value.filter((t) => t.pinned || t.path === path)
  }

  /** 关闭左侧（固定标签不受影响） */
  function closeLeft(path: string): void {
    const index = findIndex(path)
    if (index < 0) {
      return
    }
    tabs.value = tabs.value.filter((t, i) => t.pinned || i >= index)
  }

  /** 关闭右侧 */
  function closeRight(path: string): void {
    const index = findIndex(path)
    if (index < 0) {
      return
    }
    tabs.value = tabs.value.filter((t, i) => t.pinned || i <= index)
  }

  /** 关闭全部，返回应该跳转的首页路径 */
  function closeAll(): string {
    tabs.value = tabs.value.filter((t) => t.pinned)
    return HOME_PATH
  }

  /** 固定 / 取消固定：固定项整体排到前面 */
  function togglePin(path: string): void {
    const tab = tabs.value[findIndex(path)]
    if (!tab || tab.path === HOME_PATH) {
      return
    }
    tab.pinned = !tab.pinned
    tabs.value = [...tabs.value.filter((t) => t.pinned), ...tabs.value.filter((t) => !t.pinned)]
  }

  /**
   * 刷新指定页面。
   *
   * keep-alive 的 include 只决定「能否复用缓存」：把组件名移出 include 时，
   * KeepAlive 只是删掉缓存并清掉当前 vnode 的 keep-alive 标记，正在显示的实例并不会被销毁。
   * 因此必须再让组件 key 变一次，渲染器才会真正卸载旧实例、挂载新实例（即刷新）。
   *
   * 三步顺序不能颠倒：
   * 1. 移出 include —— 清掉 keep-alive 标记，否则下一步卸载会被当成 deactivate，旧实例变成游离的僵尸；
   * 2. 自增序号 —— key 变化触发真正的卸载 + 重新挂载；
   * 3. 放回 include —— 让新实例重新进入缓存。
   */
  async function refresh(path: string): Promise<void> {
    const tab = tabs.value[findIndex(path)]
    if (!tab || excluded.value.includes(tab.name)) {
      return
    }
    excluded.value = [...excluded.value, tab.name]
    // 等这一轮渲染（含 KeepAlive 的 post 冲刷）把标记与缓存清理完，再改 key
    await nextTick()
    stamps.value = { ...stamps.value, [path]: (stamps.value[path] ?? 0) + 1 }
    window.setTimeout(() => {
      excluded.value = excluded.value.filter((n) => n !== tab.name)
    }, REFRESH_DELAY)
  }

  /** 组件的 key：路径 + 刷新序号。刷新时序号自增即可让页面重新挂载 */
  function keyOf(path: string): string {
    return `${path}#${stamps.value[path] ?? 0}`
  }

  /** 刷新当前激活标签 */
  async function refreshActive(): Promise<void> {
    await refresh(active.value)
  }

  function reset(): void {
    tabs.value = [createHomeTab()]
    active.value = HOME_PATH
    excluded.value = []
    stamps.value = {}
  }

  return {
    tabs,
    cached,
    active,
    setActive,
    addTab,
    closeTab,
    closeOthers,
    closeLeft,
    closeRight,
    closeAll,
    togglePin,
    keyOf,
    refresh,
    refreshActive,
    reset,
  }
})
