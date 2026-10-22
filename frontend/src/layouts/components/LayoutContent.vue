<template>
  <div class="layout-content">
    <div class="layout-content-inner">
      <!--
        文档级标题（仅给读屏软件）。
        后台页面靠「面包屑 + 标签栏」表达位置，视觉上不需要再来一行大标题，
        但文档大纲里不能没有 h1 —— 否则读屏用户拿不到「这是哪一页」。
        工作台自己渲染了可见的 h1（问候语），这里跳过，避免出现两个 h1。
      -->
      <h1 v-if="srTitle" class="art-sr-only">{{ srTitle }}</h1>

      <router-view v-slot="{ Component, route }">
        <transition name="fade-slide" mode="out-in" appear>
          <keep-alive :include="worktab.cached" :max="15">
            <component :is="Component" :key="worktab.keyOf(route.path)" />
          </keep-alive>
        </transition>
      </router-view>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * 内容区：keep-alive 的 include 取「已打开标签的组件名」，
 * 于是关闭标签 = 从名单移除 = 实例真正销毁，内存不会无限增长。
 *
 * 组件 key 由 worktab.keyOf(path) 生成（路径 + 刷新序号）：只改 include 无法让
 * 正在显示的实例重新挂载，「刷新」还需要 key 变化配合，详见 worktab.refresh()。
 *
 * 注意：页面组件必须用 `defineOptions({ name: '<菜单 slug>' })` 声明组件名，
 * 否则 include 匹配不上（页面文件都叫 index.vue，推断出的名字会互相撞车）。
 *
 * 还有一条同样硬的约束：**页面模板必须是单根元素**。
 *
 * 若在 `<template>` 顶层先写一段注释（或任何第二个节点），页面就成了多根
 * （Fragment）组件，而 `mode="out-in"` 的 `<Transition>` 处理不了多根子节点。
 * 现象很有迷惑性：**点菜单切页时 URL 变了但内容区一片空白，按 F5 刷新却能正常加载**
 * —— 因为 F5 走的是初始挂载（不经过 out-in 的「先离开、再进入」状态机），
 * 而 SPA 切页必须走完整的离开 → 进入流程。排查时容易误判成路由或接口问题。
 *
 * 顶层说明文字请写进根元素内部（`<div>` 里第一行即可）。
 *
 * 内容区自身不滚动，滚动交给页面：列表页用 .art-fill，普通页用 .art-scroll。
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { translateTitle } from '@/locales/title'
import { useWorktabStore } from '@/stores/worktab'

const worktab = useWorktabStore()
const route = useRoute()

/** 当前页面标题；工作台有自己的可见 h1，不再重复输出 */
const srTitle = computed(() => {
  if (route.name === 'dashboard') {
    return ''
  }
  return translateTitle(String(route.meta?.title ?? ''))
})
</script>

<style scoped>
.layout-content {
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

.layout-content-inner {
  width: 100%;
  max-width: var(--art-container-width);
  height: 100%;
  padding: 16px;
  margin: 0 auto;
}
</style>
