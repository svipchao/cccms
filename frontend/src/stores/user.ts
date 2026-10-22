import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { clearToken, getToken, setToken } from '@/utils/auth'
import { login as loginApi, logout as logoutApi, me as meApi, type LoginParams, type Profile } from '@/api/auth'
import { switchTenant as switchTenantApi } from '@/api/tenant'

export const useUserStore = defineStore('user', () => {
  const token = ref<string>(getToken())
  const profile = ref<Profile | null>(null)

  const permissions = computed<string[]>(() => profile.value?.permissions ?? [])
  const superAdmin = computed<boolean>(() => !!profile.value?.super_admin)
  const nickname = computed<string>(() => profile.value?.nickname || profile.value?.username || '')

  /** 当前**生效**租户（超管切换后与归属租户不同） */
  const tenantId = computed<number>(() => profile.value?.tenant_id ?? 0)
  /** 账号归属租户 */
  const homeTenantId = computed<number>(() => profile.value?.home_tenant_id ?? 0)
  /** 是否正处于「切换后的租户」 */
  const tenantSwitched = computed<boolean>(() => !!profile.value?.tenant_switched)

  function hasAuth(node: string | string[]): boolean {
    if (superAdmin.value) {
      return true
    }
    const nodes = Array.isArray(node) ? node : [node]
    return nodes.some((n) => permissions.value.includes(n))
  }

  async function login(params: LoginParams): Promise<void> {
    const res = await loginApi(params)
    setToken(res.token.token)
    token.value = res.token.token
    if (res.user) {
      profile.value = res.user as unknown as Profile
    }
  }

  async function fetchProfile(): Promise<Profile> {
    const p = await meApi()
    profile.value = p
    return p
  }

  /**
   * 切换生效租户（超管专属，后端会校验）。
   *
   * 必须**先替换令牌再刷新其它状态**：租户声明随令牌走，落后的旧令牌会继续按旧租户解析。
   */
  async function switchTenant(id: number): Promise<void> {
    const res = await switchTenantApi(id)
    setToken(res.token.token)
    token.value = res.token.token
    profile.value = res.user
  }

  async function logout(): Promise<void> {
    try {
      await logoutApi()
    } catch {
      // 忽略登出接口异常
    }
    clearToken()
    token.value = ''
    profile.value = null
  }

  return {
    token,
    profile,
    permissions,
    superAdmin,
    nickname,
    tenantId,
    homeTenantId,
    tenantSwitched,
    hasAuth,
    login,
    fetchProfile,
    switchTenant,
    logout,
  }
})
