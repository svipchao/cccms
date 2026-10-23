<template>
  <div class="tagbar">
    <el-scrollbar class="tagbar-scroll">
      <div class="tagbar-list">
        <div
          v-for="tab in worktab.tabs"
          :key="tab.path"
          class="tagbar-item"
          :class="{ 'is-active': worktab.active === tab.path }"
          @click="go(tab.path)"
          @contextmenu.prevent="openContextMenu($event, tab.path)"
        >
          <el-icon v-if="tab.icon" :size="13" class="tagbar-item-icon">
            <ArtIcon :name="tab.icon" />
          </el-icon>
          <span class="tagbar-item-text">{{
            tab.path === HOME_PATH ? t('core.layout.home') : translateTitle(tab.title)
          }}</span>
          <el-icon v-if="!tab.pinned" class="tagbar-item-close" :size="12" @click.stop="close(tab.path)">
            <i class="ri-close-line" />
          </el-icon>
        </div>
      </div>
    </el-scrollbar>

    <div class="tagbar-extra">
      <!-- 收藏当前标签页：与顶栏快捷导航共用同一份收藏（store 层同步） -->
      <el-tooltip
        v-if="canFavorite"
        :content="activeFavorite ? t('core.layout.tagbar.favoriteRemove') : t('core.layout.tagbar.favoriteAdd')"
        placement="bottom"
      >
        <el-button text circle size="small" :class="{ 'is-favorite': activeFavorite }" @click="toggleFavoriteActive">
          <el-icon :size="15">
            <i class="ri-star-fill" v-if="activeFavorite" />
            <i class="ri-star-line" v-else />
          </el-icon>
        </el-button>
      </el-tooltip>

      <el-tooltip :content="t('core.layout.refreshPage')" placement="bottom">
        <el-button text circle size="small" @click="refreshActive">
          <el-icon :size="15"><i class="ri-refresh-line" /></el-icon>
        </el-button>
      </el-tooltip>

      <el-dropdown trigger="click" @command="onDropdownCommand">
        <el-button text circle size="small">
          <el-icon :size="15"><i class="ri-more-2-fill" /></el-icon>
        </el-button>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="closeOthers">{{ t('core.layout.tagbar.closeOthers') }}</el-dropdown-item>
            <el-dropdown-item command="closeLeft">{{ t('core.layout.tagbar.closeLeft') }}</el-dropdown-item>
            <el-dropdown-item command="closeRight">{{ t('core.layout.tagbar.closeRight') }}</el-dropdown-item>
            <el-dropdown-item command="closeAll" divided>{{ t('core.layout.tagbar.closeAll') }}</el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>

    <teleport to="body">
      <ul v-if="contextVisible" class="tagbar-context" :style="{ left: `${contextPos.x}px`, top: `${contextPos.y}px` }">
        <li @click="runContext('refresh')">{{ t('core.layout.tagbar.refresh') }}</li>
        <li v-if="contextPinnable" @click="runContext('pin')">
          {{ contextPinned ? t('core.layout.tagbar.unpin') : t('core.layout.tagbar.pin') }}
        </li>
        <li @click="runContext('close')">{{ t('core.layout.tagbar.close') }}</li>
        <li @click="runContext('closeOthers')">{{ t('core.layout.tagbar.closeOthers') }}</li>
        <li @click="runContext('closeLeft')">{{ t('core.layout.tagbar.closeLeft') }}</li>
        <li @click="runContext('closeRight')">{{ t('core.layout.tagbar.closeRight') }}</li>
        <li @click="runContext('closeAll')">{{ t('core.layout.tagbar.closeAll') }}</li>
      </ul>
    </teleport>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ArtIcon from '@/components/core/ArtIcon.vue'
import { translateTitle } from '@/locales/title'
import { useMenuStore } from '@/stores/menu'
import { HOME_PATH, useWorktabStore } from '@/stores/worktab'

const { t } = useI18n({ useScope: 'global' })

const router = useRouter()
const worktab = useWorktabStore()
const menuStore = useMenuStore()

/** 当前标签页是否可收藏：不在菜单里的页面（如个人中心）没有菜单项，无从收藏 */
const canFavorite = computed(() => menuStore.flatMenus.some((item) => item.path === worktab.active))
const activeFavorite = computed(() => menuStore.isFavorite(worktab.active))

function toggleFavoriteActive(): void {
  menuStore.toggleFavorite(worktab.active)
}

const contextVisible = ref(false)
const contextPos = reactive({ x: 0, y: 0 })
const contextTarget = ref<string>('')

const contextPinnable = computed(() => contextTarget.value !== HOME_PATH)
const contextPinned = computed(() => worktab.tabs.find((t) => t.path === contextTarget.value)?.pinned ?? false)

function go(path: string): void {
  worktab.setActive(path)
  if (router.currentRoute.value.path !== path) {
    router.push(path)
  }
}

function close(path: string): void {
  const next = worktab.closeTab(path)
  if (next) {
    go(next)
  }
}

/** 刷新当前标签页（移出 keep-alive include + 变更组件 key，让页面重新挂载） */
function refreshActive(): void {
  void worktab.refreshActive()
}

function openContextMenu(event: MouseEvent, path: string): void {
  contextTarget.value = path
  contextPos.x = event.clientX
  contextPos.y = event.clientY
  contextVisible.value = true
}

function hideContextMenu(): void {
  contextVisible.value = false
}

function runContext(command: string): void {
  hideContextMenu()
  apply(command, contextTarget.value)
}

function onDropdownCommand(command: string | number | object): void {
  apply(String(command), worktab.active)
}

function apply(command: string, path: string): void {
  switch (command) {
    case 'refresh':
      void worktab.refresh(path)
      break
    case 'pin':
      worktab.togglePin(path)
      break
    case 'close':
      close(path)
      break
    case 'closeOthers':
      worktab.closeOthers(path)
      go(path)
      break
    case 'closeLeft':
      worktab.closeLeft(path)
      break
    case 'closeRight':
      worktab.closeRight(path)
      break
    case 'closeAll':
      go(worktab.closeAll())
      break
    default:
      break
  }
}

onMounted(() => document.addEventListener('click', hideContextMenu))
onBeforeUnmount(() => document.removeEventListener('click', hideContextMenu))
</script>

<style scoped>
.tagbar {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  height: var(--art-tagbar-height);
  padding: 0 8px;
  background: var(--art-header-bg);
  border-bottom: 1px solid var(--art-card-border);
}

.tagbar-scroll {
  flex: 1;
  height: 100%;
  min-width: 0;
}

/* 标签只做横向滚动：纵向永远不该出现滚动条。
   （内容高度 = 4 + 28 + 4 = 36px < 40 - 1px 边框，纵向放得下） */
.tagbar-scroll :deep(.el-scrollbar__wrap) {
  overflow-y: hidden;
}

.tagbar-list {
  display: flex;
  gap: 6px;
  align-items: center;
  padding: 4px 0;
}

.tagbar-item {
  display: flex;
  flex-shrink: 0;
  gap: 5px;
  align-items: center;
  height: 28px;
  padding: 0 10px;
  font-size: 13px;
  color: var(--art-sub);
  cursor: pointer;
  background: var(--art-hover-bg);
  border: 1px solid transparent;
  border-radius: calc(var(--art-radius) - 3px);
  transition: all 0.15s ease;
  user-select: none;
}

.tagbar-item:hover {
  color: var(--el-color-primary);
}

.tagbar-item.is-active {
  font-weight: 500;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  border-color: var(--el-color-primary-light-7);
}

.tagbar-item-text {
  max-width: 130px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tagbar-item-close {
  border-radius: 50%;
  transition: background 0.15s ease;
}

.tagbar-item-close:hover {
  color: #fff;
  background: var(--el-color-danger);
}

.tagbar-extra {
  display: flex;
  flex-shrink: 0;
  gap: 2px;
  align-items: center;
  padding-left: 6px;
}

/* 已收藏：金色实心星 + 悬停底色，与未收藏（灰色轮廓星）一眼可辨 */
.tagbar-extra .is-favorite {
  color: var(--art-warning);
  background: var(--art-hover-bg);
}

.tagbar-context {
  position: fixed;
  z-index: 3000;
  min-width: 130px;
  padding: 4px;
  margin: 0;
  font-size: 13px;
  list-style: none;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius);
  box-shadow: var(--art-shadow-2);
}

.tagbar-context li {
  padding: 7px 12px;
  color: var(--art-sub);
  cursor: pointer;
  border-radius: calc(var(--art-radius) - 4px);
}

.tagbar-context li:hover {
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}
</style>
