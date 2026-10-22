import { getCurrentInstance, provide, reactive, ref, watch, type InjectionKey, type Ref } from 'vue'
import { useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import type { PageResult } from '@/types/table'

/**
 * 加载动画的最短可见时长（ms）。
 *
 * 原先是 300ms：本地接口十几毫秒就返回，为了「让 loading 别一闪而过」硬等 300ms，
 * 等于给每次查询/翻页都人为加了 200~300ms 的等待。降到 120ms 既能避免闪烁
 * （一帧约 16ms，120ms 已经足够看清状态变化），又不至于让用户感觉卡顿。
 */
const MIN_LOADING_MS = 120

/** 筛选条件自动记忆的存储前缀（沿用 `cccms_table_width:` 的命名风格） */
const FILTER_PREFIX = 'cccms_table_filter:'
/** 命名筛选方案的存储前缀 */
const FILTER_SCHEME_PREFIX = 'cccms_table_filter_scheme:'

/** 列头排序状态；`prop` 是列的 prop，`order` 是服务端认识的方向 */
export interface TableSortState {
  prop: string
  order: 'asc' | 'desc'
}

/** 持久化的筛选快照：查询条件 + 每页条数 + 排序（**不含 page**，页码不属于筛选方案） */
export interface TableFilterSnapshot {
  /** 查询条件（普通对象，JSON 可序列化） */
  query: Record<string, unknown>
  /** 每页条数（无分页的树表不存该字段） */
  limit?: number
  /** 列头排序（无排序时不写该字段） */
  sort?: TableSortState
}

/**
 * 表格「筛选方案」控制器。
 *
 * `useTable` / `useTableFilter` 在页面 setup 里把它 `provide` 出去，`ArtTable` 通过 `inject` 拿到，
 * 所以列表页**无需任何接线**即可在工具栏出现「筛选方案」入口。
 */
export interface TableFilterController {
  /** 当前表格的持久化标识（`defineOptions({ name })` 或路由 path） */
  key: string
  /** 本页是否已有自动记忆的筛选条件 */
  hasSaved: () => boolean
  /** 清除自动记忆（命名方案不受影响） */
  clearSaved: () => void
  /** 已保存的命名方案名 */
  listSchemes: () => string[]
  /** 把当前条件存为命名方案 */
  saveScheme: (name: string) => void
  /** 载入命名方案（回到第 1 页并重新查询） */
  applyScheme: (name: string) => void
  /** 删除命名方案 */
  removeScheme: (name: string) => void
  /** 列头筛选：读取某字段当前值 */
  readFilter: (key: string) => unknown
  /** 列头筛选：写入某字段并重新查询 */
  writeFilter: (key: string, value: unknown) => void
  /** 列头筛选：批量写入多个字段（如日期范围），只触发一次重新查询 */
  writeFilters: (fields: Record<string, unknown>) => void
}

/** ArtTable 通过它拿到所属页面的筛选方案控制器 */
export const TABLE_FILTER_KEY: InjectionKey<TableFilterController> = Symbol('cccms-table-filter')

/**
 * 表格「列头排序」控制器。
 *
 * 传 `sort` 用 Ref 而不是普通值，便于 ArtTable 在列设置关闭排序、或恢复记忆的排序时
 * 响应式地重算 `default-sort`（服务端排序只认接口返回的顺序，前端不排）。
 */
export interface TableSortController {
  /** 当前排序（null = 未排序，走接口默认顺序） */
  sort: Ref<TableSortState | null>
  /** 写入排序并重新查询；`prop` / `order` 为空表示清除排序 */
  writeSort: (prop: string, order: 'asc' | 'desc' | '') => void
}

/** ArtTable 通过它拿到所属页面的排序控制器 */
export const TABLE_SORT_KEY: InjectionKey<TableSortController> = Symbol('cccms-table-sort')

function readJson<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) {
      return null
    }
    const parsed: unknown = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? (parsed as T) : null
  } catch {
    // 解析异常时视为「无记忆」
    return null
  }
}

function writeJson(key: string, value: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // localStorage 不可用时忽略
  }
}

/** 展开成普通对象，避免把 reactive 代理直接序列化 */
function toQueryRecord(source: object): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(source)) {
    out[key] = value
  }
  return out
}

/**
 * 持久化标识：优先取页面 `defineOptions({ name })`（菜单 slug），
 * 拿不到时回落到当前路由 path —— 两者在同一页面内都稳定。
 */
function resolveFilterKey(): string {
  const instance = getCurrentInstance()
  const name = (instance?.type as { name?: string } | undefined)?.name
  if (name) {
    return name
  }
  try {
    return useRoute().path
  } catch {
    return 'default'
  }
}

interface FilterStoreOptions {
  /** 从快照回填每页条数（无分页的树表不传） */
  restoreLimit?: (limit: number) => void
  /** 生成快照时读取当前每页条数（无分页的树表不传） */
  readLimit?: () => number
  /** 从快照回填排序（无服务端排序的树表不传） */
  restoreSort?: (sort: TableSortState | null) => void
  /** 生成快照时读取当前排序（无服务端排序的树表不传） */
  readSort?: () => TableSortState | null
  /** 条件变化后的回调：服务端分页场景回到第 1 页并重新请求；纯前端过滤场景无需传 */
  onChange?: () => void
}

/**
 * 筛选方案的读写内核，`useTable`（服务端分页）与 `useTableFilter`（纯前端过滤）共用：
 * 自动记忆当前条件 + 命名方案的增删查改，并把控制器交给 ArtTable 渲染入口。
 */
function createFilterStore<Q extends object>(initialQuery: Q, options: FilterStoreOptions = {}) {
  const query = reactive({ ...initialQuery }) as Q
  const filterKey = resolveFilterKey()
  const savedKey = FILTER_PREFIX + filterKey
  const schemeKey = FILTER_SCHEME_PREFIX + filterKey

  /**
   * 只回填页面声明过的字段（`initialQuery` 的 key 即白名单），
   * 避免旧版本存下、现已被页面删除的字段被重新发给接口；
   * 页面未声明 initialQuery 时退化为「原样回填」。
   */
  function normalizeQuery(raw: unknown): Record<string, unknown> {
    if (!raw || typeof raw !== 'object') {
      return {}
    }
    const source = raw as Record<string, unknown>
    const known = Object.keys(initialQuery)
    if (known.length === 0) {
      return { ...source }
    }
    const out: Record<string, unknown> = {}
    for (const key of known) {
      if (key in source) {
        out[key] = source[key]
      }
    }
    return out
  }

  function snapshot(): TableFilterSnapshot {
    const limit = options.readLimit?.()
    const sort = options.readSort?.() ?? null
    const snap: TableFilterSnapshot = { query: toQueryRecord(query) }
    if (limit) {
      snap.limit = limit
    }
    if (sort) {
      snap.sort = sort
    }
    return snap
  }

  function applySnapshot(snap: TableFilterSnapshot): void {
    Object.assign(query, normalizeQuery(snap.query))
    if (typeof snap.limit === 'number' && snap.limit > 0) {
      options.restoreLimit?.(snap.limit)
    }
    options.restoreSort?.(snap.sort ?? null)
  }

  // 进页面先回填上次的条件；此时 watch 尚未注册，不会回写
  const stored = readJson<TableFilterSnapshot>(savedKey)
  if (stored) {
    applySnapshot(stored)
  }

  function readSchemes(): Record<string, TableFilterSnapshot> {
    return readJson<Record<string, TableFilterSnapshot>>(schemeKey) ?? {}
  }

  function persist(): void {
    writeJson(savedKey, snapshot())
  }

  function clearSaved(): void {
    try {
      localStorage.removeItem(savedKey)
    } catch {
      // localStorage 不可用时忽略
    }
  }

  function saveScheme(name: string): void {
    const schemes = readSchemes()
    schemes[name] = snapshot()
    writeJson(schemeKey, schemes)
  }

  function removeScheme(name: string): void {
    const schemes = readSchemes()
    if (!(name in schemes)) {
      return
    }
    delete schemes[name]
    writeJson(schemeKey, schemes)
  }

  function controller(applyScheme: (name: string) => void): TableFilterController {
    return {
      key: filterKey,
      hasSaved: () => readJson<TableFilterSnapshot>(savedKey) !== null,
      clearSaved,
      listSchemes: () => Object.keys(readSchemes()),
      saveScheme,
      applyScheme,
      removeScheme,
      readFilter: (key: string) => (query as Record<string, unknown>)[key],
      writeFilter: (key: string, value: unknown) => {
        ;(query as Record<string, unknown>)[key] = value
        options.onChange?.()
      },
      writeFilters: (fields: Record<string, unknown>) => {
        Object.assign(query, fields)
        options.onChange?.()
      },
    }
  }

  return { query, applySnapshot, readSchemes, persist, controller }
}

export interface UseTableOptions<T, Q extends object> {
  /**
   * 列表请求：会带上 query / page / limit。
   * 返回体用 PageResult<unknown> 接收，避免每个接口都得把行类型透传成泛型。
   */
  api: (params: Record<string, unknown>) => Promise<PageResult<unknown>>
  /** 查询条件初始值（重置时回到这里） */
  initialQuery?: Q
  /** 每页条数，默认 15 */
  pageSize?: number
  /** 是否在 setup 阶段立即加载，默认 true */
  immediate?: boolean
  /** 请求成功后的数据加工（如补全 URL、格式化字段） */
  transform?: (list: T[]) => T[]
}

/**
 * 列表页通用逻辑：查询 / 分页 / 加载 / 多选。
 *
 * 用法：
 *   interface Query { username?: string; status?: number }
 *   const table = useTable<UserRow, Query>({ api: userList, initialQuery: { username: '' } })
 *   table.query.username / table.list / table.total ...
 *
 * 「筛选方案」默认开启且零配置：当前条件（含 limit、不含 page）会自动写入
 * `localStorage['cccms_table_filter:<name>']`，下次进同一页面自动回填；
 * 命名方案存在 `localStorage['cccms_table_filter_scheme:<name>']`，入口由 ArtTable 工具栏提供。
 */
export function useTable<T = Record<string, unknown>, Q extends object = Record<string, unknown>>(
  options: UseTableOptions<T, Q>,
) {
  const list = ref([]) as Ref<T[]>
  const loading = ref(false)
  const total = ref(0)
  const page = ref(1)
  // 默认每页条数取自后台配置 ui.page_size
  const limit = ref(options.pageSize ?? useAppStore().pageSize)
  const selection = ref([]) as Ref<T[]>

  const initialQuery = { ...(options.initialQuery ?? ({} as Q)) } as Q

  /** 列头排序状态（服务端排序，前端不对数据重排） */
  const sort = ref<TableSortState | null>(null)

  /* ---- 筛选方案：自动记忆 + 命名方案（页面零配置） ---- */
  const store = createFilterStore(initialQuery, {
    restoreLimit: (value) => {
      limit.value = value
    },
    readLimit: () => limit.value,
    // 排序与筛选条件一起记忆：刷新后表头箭头与数据顺序都能对上
    restoreSort: (value) => {
      sort.value = value
    },
    readSort: () => sort.value,
    // 列头筛选改的是查询条件，和「查询」按钮一样要回到第 1 页重新请求
    onChange: () => {
      page.value = 1
      void load()
    },
  })
  const query = store.query

  function applyScheme(name: string): void {
    const snap = store.readSchemes()[name]
    if (!snap) {
      return
    }
    store.applySnapshot(snap)
    page.value = 1
    void load()
  }

  // 条件、每页条数或排序一变就自动记忆（页码不存）
  watch(query, store.persist, { deep: true })
  watch(limit, store.persist)
  watch(sort, store.persist, { deep: true })

  if (getCurrentInstance()) {
    provide(TABLE_FILTER_KEY, store.controller(applyScheme))
    provide(TABLE_SORT_KEY, { sort, writeSort })
  }

  // 请求序号：快速连续翻页/筛选时，先发起的慢请求可能后返回并覆盖较新的结果
  let requestSeq = 0

  async function load(): Promise<void> {
    const seq = ++requestSeq
    loading.value = true
    const startedAt = Date.now()
    try {
      const params: Record<string, unknown> = { ...query, page: page.value, limit: limit.value }
      // 只在有排序时下发；清除排序后不带参数，由后端回到默认顺序
      if (sort.value) {
        params.sort = sort.value.prop
        params.order = sort.value.order
      }
      const res = await options.api(params)
      // 过期响应直接丢弃，避免「翻到第 2 页却显示第 1 页数据」
      if (seq !== requestSeq) {
        return
      }
      const rows = (res?.list ?? []) as T[]
      list.value = options.transform ? options.transform(rows) : rows
      total.value = res?.total ?? 0
    } finally {
      // 本地接口往往十几毫秒就返回，加载动画一闪而过会让人以为「没刷新」。
      // 数据已经在上面赋值了，这里只是让 loading 至少可见一小段时间。
      const rest = MIN_LOADING_MS - (Date.now() - startedAt)
      if (rest > 0) {
        await new Promise<void>((resolve) => {
          window.setTimeout(resolve, rest)
        })
      }
      // 只有最新请求才负责收起 loading，避免旧请求提前收掉
      if (seq === requestSeq) {
        loading.value = false
      }
    }
  }

  /** 条件查询：回到第 1 页 */
  function search(): void {
    page.value = 1
    void load()
  }

  /** 重置查询条件与排序并重新加载 */
  function reset(): void {
    Object.assign(query, initialQuery)
    sort.value = null
    page.value = 1
    void load()
  }

  /**
   * 列头排序：写入状态并回到第 1 页重新请求。
   *
   * 传空 `prop` / `order` 表示清除排序（走接口默认顺序）。
   * 排序由 `ArtTable` 通过 `TABLE_SORT_KEY` 自动调用，页面无需接线。
   */
  function writeSort(prop: string, order: 'asc' | 'desc' | ''): void {
    if (!prop || order === '') {
      if (sort.value === null) {
        return
      }
      sort.value = null
    } else {
      if (sort.value?.prop === prop && sort.value.order === order) {
        return
      }
      sort.value = { prop, order }
    }
    page.value = 1
    void load()
  }

  function onPageChange(value: number): void {
    page.value = value
    void load()
  }

  function onLimitChange(value: number): void {
    limit.value = value
    page.value = 1
    void load()
  }

  function onSelectionChange(rows: T[]): void {
    selection.value = rows
  }

  if (options.immediate !== false) {
    void load()
  }

  return {
    list,
    loading,
    total,
    page,
    limit,
    query,
    sort,
    selection,
    load,
    search,
    reset,
    writeSort,
    onPageChange,
    onLimitChange,
    onSelectionChange,
  }
}

/**
 * 纯前端筛选页面的「筛选方案」支持：树表这类一次性拉全量、靠 computed 过滤的场景。
 *
 * 只负责条件对象与持久化，不涉及分页与请求 —— 过滤结果由页面自己的 computed 响应。
 * 用法：
 *   const { query, reset } = useTableFilter<Query>({ initialQuery: { name: '', leader: '' } })
 */
export function useTableFilter<Q extends object = Record<string, unknown>>(options: { initialQuery?: Q } = {}) {
  const initialQuery = { ...(options.initialQuery ?? ({} as Q)) } as Q
  const store = createFilterStore(initialQuery)

  watch(store.query, store.persist, { deep: true })

  /** 载入命名方案：纯前端过滤无需重新请求，computed 会自动重算 */
  function applyScheme(name: string): void {
    const snap = store.readSchemes()[name]
    if (snap) {
      store.applySnapshot(snap)
    }
  }

  if (getCurrentInstance()) {
    provide(TABLE_FILTER_KEY, store.controller(applyScheme))
  }

  /** 重置查询条件（无需重新请求，computed 会自动重算） */
  function reset(): void {
    Object.assign(store.query, initialQuery)
  }

  return { query: store.query, reset }
}
