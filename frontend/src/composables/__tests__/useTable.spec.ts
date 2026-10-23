import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h, inject, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TABLE_FILTER_KEY, useTable, useTableFilter, type TableFilterController } from '@/composables/useTable'
import type { PageResult } from '@/types/table'

/** 页面配置接口/store 不是本用例的对象，直接给出固定分页大小，避免拉后台配置 */
vi.mock('@/stores/app', () => ({
  useAppStore: () => ({ pageSize: 15 }),
}))

interface Row {
  id: number
}

interface Query {
  username: string
  status?: string
}

const FILTER_KEY = 'cccms_table_filter:test:user'
const SCHEME_KEY = 'cccms_table_filter_scheme:test:user'

function emptyPage(): PageResult<unknown> {
  return { list: [], total: 0 }
}

/**
 * 等待筛选条件落盘。
 *
 * 持久化是 200ms 防抖（避免搜索框逐字符同步写 localStorage），
 * 断言 `localStorage` 前必须等抖动窗口过去，否则读到的是上一次的快照。
 */
async function flushPersist(): Promise<void> {
  await nextTick()
  await new Promise<void>((resolve) => setTimeout(resolve, 250))
}

/**
 * 在真实组件上下文里跑 composable。
 *
 * `provide` 与 `inject` **不能写在同一个组件的 setup 里**：Vue 的 `inject` 只沿
 * `instance.parent.provides` 向上找，不含本组件自己 `provide` 的东西。
 * 因此这里挂一个父组件调用 `useTable`（它内部 provide），再由子组件 inject 取控制器。
 */
function mountUseTable(
  api: (params: Record<string, unknown>, config?: { signal?: AbortSignal }) => Promise<PageResult<unknown>>,
  options: { name?: string; immediate?: boolean; transform?: (rows: Row[]) => Row[] } = {},
) {
  const captured: {
    table?: ReturnType<typeof useTable<Row, Query>>
    filter?: TableFilterController
  } = {}

  const Child = defineComponent({
    setup() {
      captured.filter = inject(TABLE_FILTER_KEY)
      return () => h('div')
    },
  })

  mount(
    defineComponent({
      name: options.name ?? 'test:user',
      setup() {
        captured.table = useTable<Row, Query>({
          api,
          initialQuery: { username: '' },
          immediate: options.immediate ?? false,
          transform: options.transform,
        })
        return () => h(Child)
      },
    }),
  )

  return captured as { table: ReturnType<typeof useTable<Row, Query>>; filter: TableFilterController }
}

function mountUseTableFilter() {
  const captured: {
    query?: Query
    filter?: TableFilterController
    reset?: () => void
  } = {}

  const Child = defineComponent({
    setup() {
      captured.filter = inject(TABLE_FILTER_KEY)
      return () => h('div')
    },
  })

  mount(
    defineComponent({
      name: 'test:dept',
      setup() {
        const state = useTableFilter<Query>({ initialQuery: { username: '' } })
        captured.query = state.query as Query
        // useTableFilter 的 reset 是 composable 的返回值（控制器里只有筛选方案相关方法）
        captured.reset = state.reset
        return () => h(Child)
      },
    }),
  )

  return captured as { query: Query; filter: TableFilterController; reset: () => void }
}

describe('useTable 筛选方案', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('load 把 query / page / limit 一并交给接口，并回填 list / total', async () => {
    const api = vi.fn(async (params: Record<string, unknown>): Promise<PageResult<unknown>> => {
      expect(params).toMatchObject({ username: '', page: 1, limit: 15 })
      return { list: [{ id: 1 }], total: 1 }
    })

    const { table } = mountUseTable(api)
    await table.load()

    expect(api).toHaveBeenCalledTimes(1)
    expect(table.list.value).toEqual([{ id: 1 }])
    expect(table.total.value).toBe(1)
    expect(table.loading.value).toBe(false)
  })

  it('条件变化自动记忆，且快照里不含 page（页码不属于筛选方案）', async () => {
    const { table } = mountUseTable(vi.fn(async () => emptyPage()))

    table.query.username = 'alice'
    table.page.value = 3
    await flushPersist()

    const saved = JSON.parse(localStorage.getItem(FILTER_KEY) ?? '{}')
    expect(saved.query).toEqual({ username: 'alice' })
    expect(saved.limit).toBe(15)
    expect(saved).not.toHaveProperty('page')
  })

  it('排序写入会回到第 1 页、下发 sort/order，并写进筛选快照', async () => {
    // 声明出参（params）才能读 lastCall?.[0]：否则会被推断成空元组，取索引报 TS2493
    const api = vi.fn(async (_params?: Record<string, unknown>) => emptyPage())
    const { table } = mountUseTable(api)

    table.page.value = 4
    table.writeSort('create_time', 'asc')

    await vi.waitFor(() => expect(api).toHaveBeenCalled())
    // 第二个参数是 { signal }，这里只看 params
    expect(api.mock.lastCall?.[0]).toEqual(
      expect.objectContaining({ page: 1, limit: 15, sort: 'create_time', order: 'asc' }),
    )
    expect(table.page.value).toBe(1)
    expect(table.sort.value).toEqual({ prop: 'create_time', order: 'asc' })

    await flushPersist()
    expect(JSON.parse(localStorage.getItem(FILTER_KEY) ?? '{}').sort).toEqual({
      prop: 'create_time',
      order: 'asc',
    })
  })

  it('恢复记忆的排序：请求带 sort/order；清除后不再下发', async () => {
    localStorage.setItem(
      FILTER_KEY,
      JSON.stringify({ query: { username: '' }, limit: 15, sort: { prop: 'login_time', order: 'desc' } }),
    )

    const api = vi.fn(async (_params?: Record<string, unknown>) => emptyPage())
    const { table } = mountUseTable(api)

    await table.load()
    expect(api.mock.lastCall?.[0]).toEqual(expect.objectContaining({ sort: 'login_time', order: 'desc' }))

    table.writeSort('', '')
    await vi.waitFor(() => expect(api).toHaveBeenCalledTimes(2))
    expect(api.mock.lastCall?.[0]).toEqual(expect.not.objectContaining({ sort: expect.anything() }))
    expect(table.sort.value).toBeNull()

    await flushPersist()
    expect(JSON.parse(localStorage.getItem(FILTER_KEY) ?? '{}')).not.toHaveProperty('sort')
  })

  it('回填只认页面声明过的字段（initialQuery 的 key 即白名单）', async () => {
    localStorage.setItem(
      FILTER_KEY,
      JSON.stringify({ query: { username: 'bob', removed_field: '旧版本字段' }, limit: 50 }),
    )

    const { table } = mountUseTable(vi.fn(async () => emptyPage()))

    expect(table.query.username).toBe('bob')
    expect(Object.keys(table.query)).not.toContain('removed_field')
    expect(table.limit.value).toBe(50)
  })

  it('损坏的快照按「无记忆」处理，不抛错', async () => {
    localStorage.setItem(FILTER_KEY, '{ 这不是合法 JSON')
    const { table } = mountUseTable(vi.fn(async () => emptyPage()))
    expect(table.query.username).toBe('')
  })

  it('reset 恢复初始条件并回到第 1 页', async () => {
    const api = vi.fn(async () => emptyPage())
    const { table } = mountUseTable(api)

    table.query.username = 'alice'
    table.page.value = 5
    table.reset()
    await table.load()

    expect(table.query.username).toBe('')
    expect(table.page.value).toBe(1)
  })

  it('search / onLimitChange 都会回到第 1 页；onPageChange 只改页码', async () => {
    const api = vi.fn(async () => emptyPage())
    const { table } = mountUseTable(api)

    table.page.value = 4
    table.search()
    expect(table.page.value).toBe(1)

    table.page.value = 4
    table.onLimitChange(50)
    expect(table.page.value).toBe(1)
    expect(table.limit.value).toBe(50)

    table.onPageChange(7)
    expect(table.page.value).toBe(7)
  })

  it('筛选控制器写值会回到第 1 页并重新查询（writeFilter / writeFilters 同一口径）', async () => {
    const api = vi.fn(async () => emptyPage())
    const { table, filter } = mountUseTable(api)

    table.page.value = 3
    filter.writeFilter('username', 'carol')
    expect(table.query.username).toBe('carol')
    expect(table.page.value).toBe(1)

    table.page.value = 3
    filter.writeFilters({ username: 'dave', status: '1' })
    expect(table.query).toEqual({ username: 'dave', status: '1' })
    expect(table.page.value).toBe(1)

    // 列头读取与页面 query 是同一份数据
    expect(filter.readFilter('status')).toBe('1')
  })

  it('命名方案：保存 / 列出 / 载入 / 删除，且与自动记忆分开存储', async () => {
    const api = vi.fn(async () => emptyPage())
    const { table, filter } = mountUseTable(api)

    expect(filter.listSchemes()).toEqual([])

    table.query.username = 'alice'
    await nextTick()
    filter.saveScheme('只看 Alice')

    table.query.username = 'bob'
    await nextTick()
    filter.saveScheme('只看 Bob')

    expect(filter.listSchemes().sort()).toEqual(['只看 Alice', '只看 Bob'])
    expect(localStorage.getItem(SCHEME_KEY)).toBeTruthy()

    // 载入方案：条件被覆盖，并回到第 1 页
    table.page.value = 4
    filter.applyScheme('只看 Alice')
    expect(table.query.username).toBe('alice')
    expect(table.page.value).toBe(1)

    filter.removeScheme('只看 Bob')
    expect(filter.listSchemes()).toEqual(['只看 Alice'])

    // 删除不存在的方案是空操作
    filter.removeScheme('不存在')
    expect(filter.listSchemes()).toEqual(['只看 Alice'])
  })

  it('clearSaved 只清自动记忆，不影响命名方案', async () => {
    const { table, filter } = mountUseTable(vi.fn(async () => emptyPage()))

    table.query.username = 'alice'
    await flushPersist()
    filter.saveScheme('方案')

    expect(filter.hasSaved()).toBe(true)
    filter.clearSaved()
    expect(filter.hasSaved()).toBe(false)
    expect(filter.listSchemes()).toEqual(['方案'])
  })

  it('默认立即加载：setup 阶段就请求一次', () => {
    const api = vi.fn(async () => emptyPage())

    mountUseTable(api, { name: 'test:immediate', immediate: true })

    expect(api).toHaveBeenCalledTimes(1)
  })

  it('immediate=false 时不在 setup 阶段请求，由调用方显式 load', async () => {
    const api = vi.fn(async () => emptyPage())

    const { table } = mountUseTable(api, { name: 'test:lazy' })
    expect(api).not.toHaveBeenCalled()

    await table.load()
    expect(api).toHaveBeenCalledTimes(1)
  })

  it('transform 用于加工接口返回的行；未传时原样使用接口数据', async () => {
    const raw = vi.fn(async (): Promise<PageResult<unknown>> => ({ list: [{ id: 1 }], total: 1 }))
    const { table } = mountUseTable(raw, { name: 'test:raw' })
    await table.load()
    expect(table.list.value).toEqual([{ id: 1 }])

    const { table: transformed } = mountUseTable(
      vi.fn(async (): Promise<PageResult<unknown>> => ({ list: [{ id: 2 }], total: 1 })),
      {
        name: 'test:transform',
        transform: (rows) => rows.map((row) => ({ ...row, doubled: row.id * 2 }) as unknown as Row),
      },
    )
    await transformed.load()
    expect(transformed.list.value).toEqual([{ id: 2, doubled: 4 }])
  })

  it('请求失败时清空列表并置 loadFailed，不残留上一次的数据', async () => {
    const api = vi
      .fn()
      .mockResolvedValueOnce({ list: [{ id: 1 }], total: 1 })
      .mockRejectedValueOnce(new Error('boom'))

    const { table } = mountUseTable(api, { name: 'test:fail' })
    await table.load()
    expect(table.list.value).toEqual([{ id: 1 }])
    expect(table.loadFailed.value).toBe(false)

    await table.load()
    expect(table.list.value).toEqual([])
    expect(table.total.value).toBe(0)
    expect(table.loadFailed.value).toBe(true)
  })

  it('新一轮请求取消上一次：旧请求被 abort，不清空数据也不置失败态', async () => {
    const pending: Array<{
      resolve: (value: PageResult<unknown>) => void
      reject: (reason: unknown) => void
      signal?: AbortSignal
    }> = []

    const api = vi.fn(
      (_params: Record<string, unknown>, config?: { signal?: AbortSignal }) =>
        new Promise<PageResult<unknown>>((resolve, reject) => {
          pending.push({ resolve, reject, signal: config?.signal })
          config?.signal?.addEventListener('abort', () => reject(new Error('aborted')))
        }),
    )

    const { table } = mountUseTable(api, { name: 'test:abort' })

    const first = table.load()
    await nextTick()
    expect(pending).toHaveLength(1)

    const second = table.load()
    expect(pending[0].signal?.aborted, '上一轮请求应被 abort').toBe(true)

    pending[1].resolve({ list: [{ id: 2 }], total: 2 })
    await second
    await first

    expect(table.list.value).toEqual([{ id: 2 }])
    expect(table.total.value).toBe(2)
    expect(table.loadFailed.value, '主动取消不是失败').toBe(false)
  })
})

describe('useTableFilter（树表等纯前端过滤场景）', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('条件变化同样自动记忆（key 取组件名）', async () => {
    const { query } = mountUseTableFilter()

    query.username = 'tree'
    await flushPersist()

    expect(JSON.parse(localStorage.getItem('cccms_table_filter:test:dept') ?? '{}').query).toEqual({
      username: 'tree',
    })
  })

  it('reset 恢复初始条件；载入命名方案会覆盖当前条件', async () => {
    const { query, filter, reset } = mountUseTableFilter()

    query.username = 'tree'
    await nextTick()
    filter.saveScheme('树方案')

    query.username = 'other'
    filter.applyScheme('树方案')
    expect(query.username).toBe('tree')

    reset()
    expect(query.username).toBe('')
  })
})
