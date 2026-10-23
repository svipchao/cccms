<template>
  <aside
    class="sidebar"
    :class="{ 'is-collapsed': collapsed, 'is-mobile': mobile, 'is-open': mobile && !collapsed }"
    :style="{ width: mobile ? '230px' : sidebarWidth }"
  >
    <!-- 用户卡片（顶部）：头像行（头像 + 信息 + 注销登录）+ 图标行（切换平台 / 主题 / 语言 / 缓存） -->
    <div class="sidebar-user">
      <div class="sidebar-user-head">
        <div class="sidebar-user-profile" @click="goProfile">
          <el-avatar :size="36" :src="userStore.profile?.avatar || undefined" class="sidebar-user-avatar">
            {{ avatarText }}
          </el-avatar>
          <div v-show="!collapsed || mobile" class="sidebar-user-info">
            <span class="sidebar-user-name">{{ userStore.nickname || t('core.layout.notLoggedIn') }}</span>
            <span class="sidebar-user-role">
              {{
                userStore.superAdmin
                  ? t('core.layout.superAdmin')
                  : (userStore.profile?.roles || []).join(' / ') || t('core.layout.normalUser')
              }}
            </span>
          </div>
        </div>

        <!-- 注销登录：与头像并排、靠右 -->
        <el-tooltip :content="t('core.layout.logout')" placement="bottom">
          <button type="button" class="sidebar-user-action" @click="onLogout">
            <i class="ri-logout-box-r-line" />
          </button>
        </el-tooltip>
      </div>

      <div class="sidebar-user-actions">
        <!-- 1. 切换平台（仅平台超管可见） -->
        <el-dropdown v-if="userStore.superAdmin" trigger="click" placement="bottom" @command="onTenantCommand">
          <button type="button" class="sidebar-user-action">
            <i class="ri-swap-line" />
          </button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item disabled>
                {{ t('cccms.tenant.currentTenant') }}：{{ currentTenantName }}
              </el-dropdown-item>
              <el-dropdown-item
                v-for="item in tenantChoices"
                :key="item.id"
                :command="item.id"
                :disabled="item.current"
              >
                {{ item.name }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>

        <!-- 2. 切换主题模式（亮/暗） -->
        <el-tooltip :content="t('core.setting.mode')" placement="bottom">
          <button type="button" class="sidebar-user-action" @click="setting.toggleDark()">
            <i :class="setting.isDark ? 'ri-sun-line' : 'ri-moon-line'" />
          </button>
        </el-tooltip>

        <!-- 3. 切换语言 -->
        <el-dropdown trigger="click" @command="onLocaleCommand">
          <button type="button" class="sidebar-user-action">
            <i class="ri-translate-2" />
          </button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item
                v-for="item in SUPPORTED_LOCALES"
                :key="item.value"
                :command="item.value"
                :disabled="item.value === currentLocale"
              >
                <span class="sidebar-locale-label">{{ item.label }}</span>
                <i v-if="item.value === currentLocale" class="ri-check-line" />
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>

        <!-- 4. 清除缓存（系统刷新：整体同步 / 菜单同步 / 权限同步 / 清理缓存） -->
        <el-dropdown v-if="canRefresh" trigger="click" placement="bottom" @command="onRefreshCommand">
          <button type="button" class="sidebar-user-action" :disabled="refreshing">
            <i class="ri-refresh-line" :class="{ 'is-spinning': refreshing }" />
          </button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-for="scope in refreshScopes" :key="scope" :command="scope" :disabled="refreshing">
                {{ refreshLabel(scope) }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

    <el-scrollbar class="sidebar-body">
      <el-menu
        ref="menuRef"
        :default-active="activeMenu"
        :collapse="!mobile && collapsed"
        :collapse-transition="false"
        unique-opened
        @select="onSelect"
      >
        <SidebarSubmenu :menus="menuStore.menus" />
      </el-menu>
    </el-scrollbar>

    <!-- 备案号 / 版权来自后台配置 -->
    <footer v-if="(!collapsed || mobile) && (appStore.icp || appStore.copyright)" class="sidebar-footer">
      <div v-if="appStore.copyright" class="sidebar-footer-line">{{ appStore.copyright }}</div>
      <div v-if="appStore.icp" class="sidebar-footer-line">{{ appStore.icp }}</div>
    </footer>
  </aside>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import SidebarSubmenu from './SidebarSubmenu.vue'
import { currentLocale, setLocale, SUPPORTED_LOCALES } from '@/locales'
import { reloadMenus, resetAfterLogout, syncDocumentTitle } from '@/router'
import { systemRefresh, type RefreshResult, type RefreshScope } from '@/api/system'
import { tenantOptions, type TenantOption } from '@/api/tenant'
import { useAppStore } from '@/stores/app'
import { useMenuStore } from '@/stores/menu'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'
import type { MenuNode } from '@/api/types'

const props = defineProps<{
  collapsed: boolean
  mobile?: boolean
}>()

const { t } = useI18n({ useScope: 'global' })
const route = useRoute()
const router = useRouter()
const menuStore = useMenuStore()
const appStore = useAppStore()
const userStore = useUserStore()
const setting = useSettingStore()

/**
 * el-menu 通过 expose 暴露了 open(index)，内部会用正确的 indexPath 展开整条链，
 * 因此这里只需要传「目录 id」。注意 index 必须是已注册的子菜单，否则会抛错。
 */
const menuRef = ref()

const activeMenu = computed(() => route.path)
const sidebarWidth = computed(() => `${props.collapsed ? 64 : 230}px`)

/** 回溯出当前路径的所有祖先目录 id */
function findChain(menus: MenuNode[], path: string, chain: string[]): string[] | null {
  for (const m of menus) {
    if (m.type === 2 && m.path === path) {
      return chain
    }
    if (m.children?.length) {
      const hit = findChain(m.children, path, [...chain, String(m.id)])
      if (hit) {
        return hit
      }
    }
  }
  return null
}

/**
 * el-menu 只在 items 变化时自动展开当前项（内部 watch(items, initMenu)），
 * 路由切换时不会，所以要手动补一次。
 */
function syncOpenMenus(): void {
  nextTick(() => {
    const chain = findChain(menuStore.menus, activeMenu.value, []) ?? []
    chain.forEach((id) => menuRef.value?.open(id))
  })
}

watch(activeMenu, syncOpenMenus)
watch(() => menuStore.menus, syncOpenMenus)
onMounted(() => {
  syncOpenMenus()
  void loadTenantChoices()
})

function onSelect(index: string): void {
  if (index.startsWith('/') && index !== route.path) {
    router.push(index)
  }
}

/* ---- 用户卡片 ---- */
const avatarText = computed(() => (userStore.nickname || 'U').charAt(0).toUpperCase())

/** 点击头像 / 用户信息 → 个人中心 */
function goProfile(): void {
  router.push('/profile')
}

/** 切换语言（与顶栏原语言切换同一套逻辑） */
function onLocaleCommand(command: string | number | object): void {
  const locale = String(command)
  if (locale === currentLocale.value) {
    return
  }
  setLocale(locale)
  void reloadMenus()
  syncDocumentTitle(String(route.meta.title ?? ''))
}

/* ---- 系统刷新（整体 / 菜单 / 权限 / 缓存；等价 menu-sync + perm-scan + 清缓存） ---- */
const refreshing = ref(false)

/** 下拉项与后端 RefreshScope 一一对应（含原「清理缓存」） */
const refreshScopes: RefreshScope[] = ['all', 'menu', 'perm', 'cache']

/** 无 `cccms:config:refresh` 的账号看不到入口（接口侧同样会拦截） */
const canRefresh = computed(() => userStore.hasAuth('cccms:config:refresh'))

function refreshLabel(scope: string): string {
  const labels: Record<string, string> = {
    all: t('core.layout.syncAll'),
    menu: t('core.layout.syncMenu'),
    perm: t('core.layout.syncPerm'),
    cache: t('core.layout.syncCacheItem'),
  }
  return labels[scope] ?? t('core.common.refresh')
}

function describeRefresh(result: RefreshResult): string {
  const parts: string[] = []
  if (result.menu) {
    parts.push(
      t('core.layout.refreshMenu', {
        created: result.menu.created,
        updated: result.menu.updated,
        removed: result.menu.removed,
      }),
    )
  }
  if (result.perm) {
    parts.push(t('core.layout.refreshPerm', { created: result.perm.created, skipped: result.perm.skipped }))
  }
  if (result.cache) {
    parts.push(t('core.layout.refreshCacheDone'))
  }
  return parts.join('；') || t('core.layout.refreshNoChange')
}

async function onRefreshCommand(command: string | number | object): Promise<void> {
  const scope = String(command) as RefreshScope

  if (scope === 'all') {
    try {
      await ElMessageBox.confirm(t('core.layout.refreshAllConfirm'), t('core.layout.refreshAllTitle'), {
        type: 'warning',
      })
    } catch {
      return
    }
  }

  refreshing.value = true
  try {
    const result = await systemRefresh(scope)
    ElMessage.success(t('core.layout.refreshDone', { name: refreshLabel(scope), detail: describeRefresh(result) }))

    // 菜单 / 按钮节点变了 → 重挂动态路由 + 刷新权限，侧边栏立即生效
    if (scope === 'menu' || scope === 'perm' || scope === 'all') {
      await reloadMenus()
    }
  } catch (error) {
    // 失败原因由拦截器统一提示；超时时后台可能仍在跑，补一句避免用户重复触发
    if ((error as { code?: string })?.code === 'ECONNABORTED') {
      ElMessage.warning(t('core.layout.refreshTimeout'))
    }
  } finally {
    refreshing.value = false
  }
}

/* ---- 切换租户（超管专属） ---- */
const tenantChoices = ref<TenantOption[]>([])

/** 当前生效租户名：优先取候选里的名字，拉取失败时回落到「当前租户」 */
const currentTenantName = computed(() => {
  const hit = tenantChoices.value.find((item) => item.id === userStore.tenantId)
  return hit?.name || t('cccms.tenant.currentTenant')
})

async function loadTenantChoices(): Promise<void> {
  if (!userStore.superAdmin) {
    return
  }
  try {
    tenantChoices.value = await tenantOptions()
  } catch {
    // 拦截器已提示，少一个入口不影响其它功能
  }
}

async function onTenantCommand(command: string | number | object): Promise<void> {
  const id = Number(command)
  if (!Number.isInteger(id) || id === userStore.tenantId) {
    return
  }

  const name = tenantChoices.value.find((item) => item.id === id)?.name ?? ''
  try {
    await ElMessageBox.confirm(t('cccms.tenant.switchConfirm', { name }), t('cccms.tenant.switchTitle'), {
      type: 'warning',
    })
  } catch {
    return
  }

  await userStore.switchTenant(id)
  ElMessage.success(t('cccms.tenant.switchSuccess', { name }))

  // 页面上的每一份数据都属于切换前的租户，且 `keep_alive` 的页面还会被缓存复用，
  // 只有整体重载才能保证不残留旧租户数据（令牌在 localStorage，重载不会丢登录态）。
  window.location.reload()
}

/** 注销登录：作废令牌 + 清理各 store + 跳登录页 */
async function onLogout(): Promise<void> {
  try {
    await ElMessageBox.confirm(t('core.layout.logoutConfirm'), t('core.layout.logout'), {
      type: 'warning',
      confirmButtonText: t('core.layout.logoutConfirmBtn'),
      cancelButtonText: t('core.common.cancel'),
    })
  } catch {
    return
  }
  await userStore.logout()
  // 账号级 store（未读角标 / 导出轮询）由 resetAfterLogout 统一清理
  resetAfterLogout()
  router.push('/login')
}
</script>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  height: 100%;
  overflow: hidden;
  background: var(--art-sidebar-bg);
  border-right: 1px solid var(--art-card-border);
  transition: width 0.2s ease;
}

.sidebar.is-mobile {
  position: fixed;
  top: 0;
  bottom: 0;
  left: 0;
  z-index: 1001;
  transform: translateX(-100%);
  transition: transform 0.25s ease;
}

.sidebar.is-mobile.is-open {
  transform: translateX(0);
}

.sidebar-body {
  flex: 1;
  min-height: 0;
}

/* ---- 用户卡片（头像行 + 图标行） ---- */
.sidebar-user {
  flex-shrink: 0;
  padding: 12px 10px;
  border-bottom: 1px solid var(--art-card-border);
}

/* 头像行：头像 + 信息靠左，注销登录靠右 */
.sidebar-user-head {
  display: flex;
  gap: 6px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.sidebar-user-profile {
  display: flex;
  flex: 1;
  gap: 10px;
  align-items: center;
  min-width: 0;
  padding: 4px 6px;
  cursor: pointer;
  border-radius: calc(var(--art-radius) - 2px);
  transition: background 0.15s ease;
}

.sidebar-user-profile:hover {
  background: var(--art-hover-bg);
}

.sidebar-user-avatar {
  flex-shrink: 0;
  background: var(--art-primary);
}

.sidebar-user-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.3;
}

.sidebar-user-name {
  overflow: hidden;
  font-size: 14px;
  font-weight: 600;
  color: var(--art-main);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar-user-role {
  overflow: hidden;
  font-size: 12px;
  color: var(--art-muted);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar-user-actions {
  display: flex;
  align-items: center;
  justify-content: space-around;
}

.sidebar-user-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  font-size: 16px;
  color: var(--art-main);
  cursor: pointer;
  background: transparent;
  border: none;
  border-radius: 50%;
  outline: none;
  transition: background-color 0.15s ease;
}

.sidebar-user-action:hover {
  background: var(--art-hover-bg);
}

.sidebar-user-action:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

/* 刷新进行中：图标旋转反馈 */
.sidebar-user-action .is-spinning {
  animation: sidebar-spin 1s linear infinite;
}

@keyframes sidebar-spin {
  to {
    transform: rotate(360deg);
  }
}

.sidebar-locale-label {
  flex: 1;
}

/* 桌面折叠：头像行竖排（头像 + 注销居中），图标行竖排 */
.sidebar.is-collapsed:not(.is-mobile) .sidebar-user-head {
  flex-direction: column;
  gap: 4px;
}

.sidebar.is-collapsed:not(.is-mobile) .sidebar-user-profile {
  flex: none;
  justify-content: center;
  padding: 4px 0;
}

.sidebar.is-collapsed:not(.is-mobile) .sidebar-user-actions {
  flex-direction: column;
  gap: 4px;
}

.sidebar-footer {
  flex-shrink: 0;
  padding: 10px 14px 14px;
  font-size: 11px;
  line-height: 1.6;
  color: var(--art-muted);
  text-align: center;
  border-top: 1px solid var(--art-card-border);
}

.sidebar-footer-line {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar :deep(.el-menu) {
  padding: 6px 8px;
  background: transparent;
  border-right: none;
}

.sidebar :deep(.el-menu-item),
.sidebar :deep(.el-sub-menu__title) {
  height: 42px;
  margin-bottom: 2px;
  line-height: 42px;
  border-radius: calc(var(--art-radius) - 2px);
}

.sidebar :deep(.el-menu-item.is-active) {
  font-weight: 600;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.sidebar :deep(.el-menu--collapse) {
  padding: 6px 4px;
}
</style>
