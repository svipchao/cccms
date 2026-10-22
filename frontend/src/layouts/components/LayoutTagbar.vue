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
            tab.path === HOME_PATH ? t('layout.home') : translateTitle(tab.title)
          }}</span>
          <!-- 关闭：把命中区做到 22×22（图标仍 12px），原来只有一个 12px 的
               图标可点，误触成本高、也摸不着。 -->
          <button
            v-if="!tab.pinned"
            type="button"
            class="tagbar-item-close"
            :aria-label="t('layout.tagbar.close')"
            :title="t('layout.tagbar.close')"
            @click.stop="close(tab.path)"
          >
            <i class="ri-close-line" />
          </button>
        </div>
      </div>
    </el-scrollbar>

    <div class="tagbar-extra">
      <!-- 收藏当前标签页：与顶栏快捷导航共用同一份收藏（store 层同步） -->
      <el-tooltip
        v-if="canFavorite"
        :content="activeFavorite ? t('layout.tagbar.favoriteRemove') : t('layout.tagbar.favoriteAdd')"
        placement="bottom"
      >
        <el-button text circle size="small" :class="{ 'is-favorite': activeFavorite }" @click="toggleFavoriteActive">
          <el-icon :size="15">
            <i class="ri-star-fill" v-if="activeFavorite" />
            <i class="ri-star-line" v-else />
          </el-icon>
        </el-button>
      </el-tooltip>

      <el-tooltip :content="t('layout.refreshPage')" placement="bottom">
        <el-button text circle size="small" @click="refreshActive">
          <el-icon :size="15"><i class="ri-refresh-line" /></el-icon>
        </el-button>
      </el-tooltip>

      <el-dropdown trigger="click" @command="onDropdownCommand">
        <el-button text circle size="small">
          <el-icon :size="15"><i class="ri-more-2-fill" /></el-icon>
        </el-button>
        <!-- 与右键菜单同一份命令定义：文案、顺序、分隔线只有一处，不会两边各说各话 -->
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item
              v-for="item in commandsFor(worktab.active)"
              :key="item.command"
              :command="item.command"
              :divided="item.divided"
            >
              {{ item.label }}
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>

    <teleport to="body">
      <ul v-if="contextVisible" class="tagbar-context" :style="{ left: `${contextPos.x}px`, top: `${contextPos.y}px` }">
        <li
          v-for="item in commandsFor(contextTarget)"
          :key="item.command"
          :class="{ 'is-divided': item.divided }"
          @click="runContext(item.command)"
        >
          {{ item.label }}
        </li>
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

/** 一个标签页操作项（右键菜单与「⋯」下拉共用） */
interface TabCommand {
  command: string
  label: string
  /** 与上一组之间画分隔线（破坏性操作单独成组） */
  divided?: boolean
}

/**
 * 标签页操作命令表。
 *
 * 原来右键菜单和「⋯」下拉是两份手写清单，条目还不一样（下拉少了刷新 / 固定 / 关闭），
 * 同一件操作换个入口就找不到。这里收敛成唯一一份定义，两个入口都从它渲染。
 */
function commandsFor(path: string): TabCommand[] {
  const pinned = worktab.tabs.find((tab) => tab.path === path)?.pinned ?? false
  const list: TabCommand[] = [{ command: 'refresh', label: t('layout.tagbar.refresh') }]

  // 首页常驻，不提供固定 / 关闭
  if (path !== HOME_PATH) {
    list.push({ command: 'pin', label: pinned ? t('layout.tagbar.unpin') : t('layout.tagbar.pin') })
    list.push({ command: 'close', label: t('layout.tagbar.close') })
  }

  list.push(
    { command: 'closeOthers', label: t('layout.tagbar.closeOthers') },
    { command: 'closeLeft', label: t('layout.tagbar.closeLeft') },
    { command: 'closeRight', label: t('layout.tagbar.closeRight') },
    { command: 'closeAll', label: t('layout.tagbar.closeAll'), divided: true },
  )
  return list
}

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

/** Esc 关闭右键菜单：自绘菜单不关会一直浮在页面上 */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && contextVisible.value) {
    hideContextMenu()
  }
}

onMounted(() => {
  document.addEventListener('click', hideContextMenu)
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', hideContextMenu)
  document.removeEventListener('keydown', onKeydown)
})
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
  gap: var(--art-sp-2);
  align-items: center;
  padding: var(--art-sp-1) 0;
}

.tagbar-item {
  display: flex;
  flex-shrink: 0;
  gap: var(--art-sp-1);
  align-items: center;
  height: 28px;
  padding: 0 var(--art-sp-3);
  font-size: var(--art-fs-dense);
  color: var(--art-sub);
  cursor: pointer;
  background: var(--art-hover-bg);
  border: 1px solid transparent;
  border-radius: calc(var(--art-radius) - 3px);
  /* 不用 `transition: all`：会让布局属性也进过渡队列，标签栏一多就容易掉帧 */
  transition:
    color 0.15s ease,
    background-color 0.15s ease,
    border-color 0.15s ease;
  user-select: none;
}

.tagbar-item:hover {
  color: var(--art-primary-text);
}

/* 选中态文字用 -text 变体：主色压在 light-9 底上只有 3.92:1，不够 AA */
.tagbar-item.is-active {
  font-weight: 500;
  color: var(--art-primary-text);
  background: var(--el-color-primary-light-9);
  border-color: var(--el-color-primary-light-7);
}

.tagbar-item-text {
  max-width: 130px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/*
 * 关闭按钮：命中区 22×22，悬停变中性色（不是红色）。
 * 红色留给「确认后的破坏性操作」；把 × 也做成红底白叉，会让一排标签
 * 在鼠标划过时不断闪红，既吵又让人不敢点。
 */
.tagbar-item-close {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  padding: 0;
  margin-right: -4px;
  font-size: 12px;
  color: var(--art-muted);
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 50%;
  transition:
    background 0.15s ease,
    color 0.15s ease;
}

.tagbar-item-close:hover {
  color: var(--art-main);
  background: var(--el-fill-color);
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
  color: var(--art-favorite);
  background: var(--art-hover-bg);
}

.tagbar-context {
  position: fixed;
  z-index: 3000;
  min-width: 132px;
  padding: var(--art-sp-1);
  margin: 0;
  font-size: var(--art-fs-dense);
  list-style: none;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: var(--art-radius);
  box-shadow: var(--art-shadow-2);
}

.tagbar-context li {
  padding: var(--art-sp-2) var(--art-sp-3);
  color: var(--art-sub);
  cursor: pointer;
  border-radius: var(--art-radius-sm);
}

/* 破坏性操作单独成组：一条分隔线比「换颜色」更稳，不会让菜单看起来在闪 */
.tagbar-context li.is-divided {
  padding-top: var(--art-sp-2);
  margin-top: var(--art-sp-1);
  border-top: 1px solid var(--art-line-subtle);
  border-radius: 0 0 var(--art-radius-sm) var(--art-radius-sm);
}

.tagbar-context li:hover {
  color: var(--art-primary-text);
  background: var(--el-color-primary-light-9);
}
</style>
