import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { login as loginApi, logout as logoutApi, me as meApi } from '@/api/auth'
import { switchTenant as switchTenantApi } from '@/api/tenant'
import { useUserStore } from '@/stores/user'
import { getToken } from '@/utils/auth'

/** 接口层不是本用例的对象，只验证 store 的状态流转 */
vi.mock('@/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
}))

vi.mock('@/api/tenant', () => ({
  switchTenant: vi.fn(),
}))

const loginMock = vi.mocked(loginApi)
const logoutMock = vi.mocked(logoutApi)
const meMock = vi.mocked(meApi)
const switchTenantMock = vi.mocked(switchTenantApi)

function profile(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    username: 'admin',
    nickname: '管理员',
    permissions: [],
    super_admin: false,
    tenant_id: 0,
    home_tenant_id: 0,
    ...overrides,
  } as never
}

describe('useUserStore', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('初始 token 取自本机存储，未登录为空串', () => {
    expect(useUserStore().token).toBe('')

    localStorage.setItem('cccms_token', 'abc')
    setActivePinia(createPinia())
    expect(useUserStore().token).toBe('abc')
  })

  it('hasAuth：超管直通，其余按权限集合判定（数组为「任一命中」）', () => {
    const store = useUserStore()

    store.profile = profile({ permissions: ['cccms:user:index'] })
    expect(store.hasAuth('cccms:user:index')).toBe(true)
    expect(store.hasAuth('cccms:user:delete')).toBe(false)
    expect(store.hasAuth(['cccms:user:delete', 'cccms:user:index'])).toBe(true)
    expect(store.hasAuth(['cccms:user:delete', 'cccms:role:index'])).toBe(false)

    // 超管不看权限集合
    store.profile = profile({ permissions: [], super_admin: true })
    expect(store.hasAuth('anything:at:all')).toBe(true)
  })

  it('nickname 回落 username；profile 为空时为空串', () => {
    const store = useUserStore()

    expect(store.nickname).toBe('')

    store.profile = profile({ nickname: '' })
    expect(store.nickname).toBe('admin')

    store.profile = profile({ nickname: '管理员' })
    expect(store.nickname).toBe('管理员')
  })

  it('租户相关派生值：默认平台租户、未切换', () => {
    const store = useUserStore()

    expect(store.tenantId).toBe(0)
    expect(store.homeTenantId).toBe(0)
    expect(store.tenantSwitched).toBe(false)

    store.profile = profile({ tenant_id: 3, home_tenant_id: 0, tenant_switched: true })
    expect(store.tenantId).toBe(3)
    expect(store.homeTenantId).toBe(0)
    expect(store.tenantSwitched).toBe(true)
  })

  it('login 写入令牌并落地用户信息', async () => {
    loginMock.mockResolvedValue({
      token: { token: 'new-token', expires_at: 0 },
      user: profile({ nickname: '管理员' }),
    } as never)

    const store = useUserStore()
    await store.login({ username: 'admin', password: 'x' } as never)

    expect(store.token).toBe('new-token')
    expect(getToken()).toBe('new-token')
    expect(store.nickname).toBe('管理员')
  })

  it('login 返回体没有 user 时不清空已有资料', async () => {
    loginMock.mockResolvedValue({ token: { token: 't2', expires_at: 0 } } as never)

    const store = useUserStore()
    store.profile = profile({ nickname: '旧资料' })
    await store.login({ username: 'admin', password: 'x' } as never)

    expect(store.token).toBe('t2')
    expect(store.nickname).toBe('旧资料')
  })

  it('logout 即使接口失败也要清干净本地状态', async () => {
    logoutMock.mockRejectedValue(new Error('network'))

    const store = useUserStore()
    store.token = 'abc'
    localStorage.setItem('cccms_token', 'abc')
    store.profile = profile()

    await store.logout()

    expect(store.token).toBe('')
    expect(store.profile).toBeNull()
    expect(getToken()).toBe('')
  })

  it('fetchProfile 刷新资料', async () => {
    meMock.mockResolvedValue(profile({ nickname: '拉回来的' }) as never)

    const store = useUserStore()
    const result = await store.fetchProfile()

    // pinia 的 state 是响应式代理，与接口返回的原对象不是同一引用，比较结构即可
    expect(result).toEqual(store.profile)
    expect(store.nickname).toBe('拉回来的')
  })

  it('switchTenant 先换令牌再落地新资料（令牌声明决定生效租户）', async () => {
    switchTenantMock.mockResolvedValue({
      token: { token: 'tenant-token', expires_at: 0 },
      user: profile({ tenant_id: 5, home_tenant_id: 0, tenant_switched: true }),
    } as never)

    const store = useUserStore()
    await store.switchTenant(5)

    expect(store.token).toBe('tenant-token')
    expect(getToken()).toBe('tenant-token')
    expect(store.tenantId).toBe(5)
    expect(store.tenantSwitched).toBe(true)
  })
})
