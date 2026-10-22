<template>
  <header class="header">
    <div class="header-left">
      <el-tooltip :content="collapsed ? t('layout.expandMenu') : t('layout.collapseMenu')" placement="bottom">
        <el-button text circle @click="toggleCollapse">
          <el-icon :size="18">
            <i v-if="!collapsed" class="ri-menu-fold-line" />
            <i v-else class="ri-menu-unfold-line" />
          </el-icon>
        </el-button>
      </el-tooltip>

      <el-breadcrumb class="header-breadcrumb" separator="/">
        <el-breadcrumb-item :to="{ path: HOME_PATH }">{{ t('layout.home') }}</el-breadcrumb-item>
        <!-- 中间层级可点击跳转（最后一级是当前页，不给链接） -->
        <el-breadcrumb-item
          v-for="(c, index) in crumbs"
          :key="c.path"
          :to="c.linkable && index < crumbs.length - 1 ? { path: c.path } : undefined"
        >
          {{ c.title }}
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <div class="header-right">
      <!-- 1. 快捷导航：搜索菜单并跳转（Ctrl/⌘ + K） -->
      <el-tooltip :content="t('layout.quickNavHotkey')" placement="bottom">
        <el-button text circle @click="openSearch">
          <el-icon :size="17"><i class="ri-search-line" /></el-icon>
        </el-button>
      </el-tooltip>

      <!-- 2. 全屏 -->
      <el-tooltip :content="t('layout.fullscreen')" placement="bottom">
        <el-button text circle @click="toggleFullscreen">
          <el-icon :size="17"><i class="ri-fullscreen-line" /></el-icon>
        </el-button>
      </el-tooltip>

      <!-- 3. 消息 + 导出任务（合并入口，抽屉内用 tabs 区分）
           角标只统计**未读消息**：原来把「未完成导出任务」也加进来，
           数字含义模糊（点开才知道 3 代表什么），也让人误以为有 3 条消息。 -->
      <el-tooltip :content="t('layout.notice')" placement="bottom">
        <el-badge :value="unreadBadge" :max="99" :hidden="unreadBadge === 0">
          <el-button text circle @click="exportTaskStore.open('notice')">
            <el-icon :size="17"><i class="ri-notification-line" /></el-icon>
          </el-button>
        </el-badge>
      </el-tooltip>

      <!-- 4. 设置 -->
      <el-tooltip :content="t('layout.themeSetting')" placement="bottom">
        <el-button text circle @click="settingsVisible = true">
          <el-icon :size="17"><i class="ri-settings-3-line" /></el-icon>
        </el-button>
      </el-tooltip>
    </div>

    <ArtSettingsDrawer v-model="settingsVisible" />

    <!-- 消息 + 导出任务合并中心 -->
    <MessageCenterDrawer />

    <!-- 快捷导航 -->
    <el-dialog
      v-model="searchVisible"
      :title="t('layout.quickNav')"
      width="560px"
      class="header-search-dialog"
      append-to-body
    >
      <el-input
        ref="searchInputRef"
        v-model="keyword"
        :placeholder="t('layout.searchPlaceholder')"
        clearable
        @keydown.enter="gotoFirst"
      >
        <template #prefix><i class="ri-search-line" /></template>
      </el-input>

      <!-- 收藏：置顶展示，可用箭头调整顺序（顺序存在本机，按用户 + 租户隔离） -->
      <div class="header-search-section">
        <div class="header-search-section-title">
          {{ t('layout.favoriteTitle') }}
          <span v-if="menuStore.favoriteMenus.length" class="header-search-section-count">
            {{ menuStore.favoriteMenus.length }}
          </span>
        </div>
        <el-empty
          v-if="menuStore.favoriteMenus.length === 0"
          :description="t('layout.favoriteEmpty')"
          :image-size="60"
        />
        <div
          v-for="(item, index) in menuStore.favoriteMenus"
          :key="item.path"
          class="header-search-item"
          :class="{ 'is-active': item.path === activeResultPath }"
          @click="goto(item.path)"
        >
          <el-icon :size="16" class="header-search-icon"><ArtIcon :name="item.icon" /></el-icon>
          <span class="header-search-title">{{ item.title }}</span>
          <span class="header-search-path">{{ item.path }}</span>
          <span class="header-search-actions">
            <el-tooltip :content="t('layout.favoriteMoveUp')" placement="top">
              <el-button
                text
                circle
                size="small"
                :disabled="index === 0"
                @click.stop="menuStore.moveFavorite(item.path, -1)"
              >
                <el-icon :size="14"><i class="ri-arrow-up-double-line" /></el-icon>
              </el-button>
            </el-tooltip>
            <el-tooltip :content="t('layout.favoriteMoveDown')" placement="top">
              <el-button
                text
                circle
                size="small"
                :disabled="index === menuStore.favoriteMenus.length - 1"
                @click.stop="menuStore.moveFavorite(item.path, 1)"
              >
                <el-icon :size="14"><i class="ri-arrow-down-double-line" /></el-icon>
              </el-button>
            </el-tooltip>
            <el-tooltip :content="t('layout.favoriteRemove')" placement="top">
              <el-button text circle size="small" @click.stop="menuStore.toggleFavorite(item.path)">
                <el-icon :size="14" class="header-search-star"><i class="ri-star-fill" /></el-icon>
              </el-button>
            </el-tooltip>
          </span>
        </div>
      </div>

      <!-- 全部菜单 / 搜索结果 -->
      <div class="header-search-section">
        <div class="header-search-section-title">
          {{ keyword.trim() ? t('layout.searchResult') : t('layout.allMenus') }}
        </div>
        <div class="header-search-list">
          <el-empty v-if="searchResult.length === 0" :description="t('layout.searchEmpty')" :image-size="60" />
          <div
            v-for="item in searchResult"
            :key="item.path"
            class="header-search-item"
            :class="{ 'is-active': item.path === activeResultPath }"
            @click="goto(item.path)"
          >
            <el-icon :size="16" class="header-search-icon"><ArtIcon :name="item.icon" /></el-icon>
            <span class="header-search-title">{{ item.title }}</span>
            <span class="header-search-path">{{ item.path }}</span>
            <span class="header-search-actions">
              <el-tooltip
                :content="menuStore.isFavorite(item.path) ? t('layout.favoriteRemove') : t('layout.favoriteAdd')"
                placement="top"
              >
                <el-button text circle size="small" @click.stop="menuStore.toggleFavorite(item.path)">
                  <el-icon :size="14" :class="{ 'header-search-star': menuStore.isFavorite(item.path) }">
                    <i v-if="menuStore.isFavorite(item.path)" class="ri-star-fill" />
                    <i v-else class="ri-star-line" />
                  </el-icon>
                </el-button>
              </el-tooltip>
            </span>
          </div>
        </div>
      </div>
    </el-dialog>
  </header>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ArtSettingsDrawer from '@/components/core/ArtSettingsDrawer.vue'
import ArtIcon from '@/components/core/ArtIcon.vue'
import MessageCenterDrawer from '@/components/MessageCenterDrawer.vue'
import type { MenuNode } from '@/api/types'
import { translateTitle } from '@/locales/title'
import { useMenuStore } from '@/stores/menu'
import { useNoticeStore } from '@/stores/notice'
import { useExportTaskStore } from '@/stores/exportTask'
import { HOME_PATH } from '@/stores/worktab'

const props = defineProps<{ collapsed: boolean }>()
const emit = defineEmits<{ 'update:collapsed': [value: boolean] }>()

const { t } = useI18n({ useScope: 'global' })

const route = useRoute()
const router = useRouter()
const menuStore = useMenuStore()
const noticeStore = useNoticeStore()
const exportTaskStore = useExportTaskStore()

const settingsVisible = ref(false)

/** 面包屑项：`linkable=false` 表示只展示文字（目录没有真实路由，给了链接也是 404） */
interface Crumb {
  path: string
  title: string
  linkable: boolean
}

/**
 * 在菜单树里找出「从根节点到命中节点」的整条链路；找不到返回空数组。
 *
 * @param nodes 菜单树
 * @param node  目标菜单的权限节点标识（对应路由 meta.node）
 */
function menuChain(nodes: MenuNode[], node: string): MenuNode[] {
  for (const item of nodes) {
    if (item.node === node) {
      return [item]
    }
    const sub = item.children?.length ? menuChain(item.children, node) : []
    if (sub.length) {
      return [item, ...sub]
    }
  }
  return []
}

/**
 * 面包屑。
 *
 * 动态路由是**扁平**注册的（目录 `type=1` 不产生路由记录，见 `router/index.ts` 的 `buildRoutes`），
 * 因此 `route.matched` 里只有叶子页 —— 直接用它渲染会丢掉目录层级
 * （「首页 → 权限配置 → 租户管理」只剩「首页 → 租户管理」）。这里改从菜单树取祖先链补上目录。
 */
const crumbs = computed<Crumb[]>(() => {
  const node = String(route.meta?.node ?? '')
  const chain = node ? menuChain(menuStore.menus, node) : []

  if (chain.length > 0) {
    return chain.map((item, index) => ({
      path: item.path,
      title: translateTitle(item.title),
      // 目录没有对应路由，不可跳转；末级是当前页，同样不给链接
      linkable: item.type !== 1 && index < chain.length - 1,
    }))
  }

  // 兜底：不在菜单树里的静态页（个人中心 / 403 / 404 等）仍按路由匹配链展示
  return (
    route.matched
      .slice(1)
      .filter((r) => r.meta?.title)
      // meta.title 可能是静态路由的 i18n key，也可能是后端已翻译好的菜单标题
      .map((r) => ({ path: r.path, title: translateTitle(r.meta.title), linkable: true }))
  )
})

function toggleCollapse(): void {
  emit('update:collapsed', !props.collapsed)
}

async function toggleFullscreen(): Promise<void> {
  if (document.fullscreenElement) {
    await document.exitFullscreen()
  } else {
    await document.documentElement.requestFullscreen()
  }
}

/* ---- 快捷导航 ---- */
const searchVisible = ref(false)
const keyword = ref('')
const searchInputRef = ref<{ focus: () => void } | null>(null)

/** 扁平菜单由 menuStore 统一维护，工作台「常用功能」也用同一份 */
const flatMenus = computed(() => menuStore.flatMenus)

const searchResult = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) {
    return flatMenus.value.slice(0, 12)
  }
  return flatMenus.value
    .filter((m) => m.title.toLowerCase().includes(kw) || m.path.toLowerCase().includes(kw))
    .slice(0, 20)
})

const activeResultPath = computed(() => searchResult.value[0]?.path ?? '')

function openSearch(): void {
  keyword.value = ''
  searchVisible.value = true
  void nextTick(() => searchInputRef.value?.focus())
}

function goto(path: string): void {
  if (!path) {
    return
  }
  searchVisible.value = false
  void router.push(path)
}

function gotoFirst(): void {
  if (activeResultPath.value) {
    goto(activeResultPath.value)
  }
}

function onHotkey(event: KeyboardEvent): void {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    openSearch()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onHotkey)
  void noticeStore.refreshUnread()
  void exportTaskStore.load({ silent: true })
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onHotkey)
})

/* ---- 消息 + 导出任务合并抽屉 ---- */

/** 顶栏角标：只表示未读消息数（导出任务在抽屉的 tab 上单独计数） */
const unreadBadge = computed(() => noticeStore.unread)
</script>

<style scoped>
.header {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  height: var(--art-header-height);
  padding: 0 12px 0 8px;
  background: var(--art-header-bg);
  border-bottom: 1px solid var(--art-card-border);
}

.header-left,
.header-right {
  display: flex;
  gap: 4px;
  align-items: center;
}

.header-breadcrumb {
  margin-left: 6px;
  font-size: var(--art-fs-dense);
}

/* 移动端：隐藏面包屑，把顶栏空间让给右侧按钮 */
@media (max-width: 900px) {
  .header-breadcrumb {
    display: none;
  }
}

/* ---- 快捷导航 ---- */
.header-search-section {
  margin-top: 12px;
}

.header-search-section-title {
  display: flex;
  gap: 6px;
  align-items: center;
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--art-muted);
}

.header-search-section-count {
  padding: 0 var(--art-sp-2);
  font-size: var(--art-fs-caption);
  line-height: 18px;
  color: var(--art-sub);
  background: var(--art-hover-bg);
  border-radius: var(--art-radius-sm);
}

.header-search-list {
  max-height: 260px;
  overflow-y: auto;
}

.header-search-item {
  display: flex;
  gap: var(--art-sp-2);
  align-items: center;
  padding: var(--art-sp-1) var(--art-sp-2) var(--art-sp-1) var(--art-sp-3);
  cursor: pointer;
  border-radius: var(--art-radius-sm);
}

.header-search-item:hover {
  background: var(--art-hover-bg);
}

.header-search-icon {
  flex-shrink: 0;
  color: var(--art-muted);
}

.header-search-title {
  overflow: hidden;
  font-size: 14px;
  color: var(--art-main);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.header-search-path {
  flex-shrink: 0;
  margin-left: auto;
  font-size: 12px;
  color: var(--art-muted);
}

/* 收藏与排序按钮：默认低存在感，悬停或已收藏时高亮 */
.header-search-actions {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  opacity: 0.55;
}

.header-search-item:hover .header-search-actions {
  opacity: 1;
}

.header-search-star {
  color: var(--art-favorite);
}
</style>
