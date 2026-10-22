<template>
  <template v-for="m in items" :key="m.id">
    <el-sub-menu v-if="hasChildren(m)" :index="String(m.id)">
      <template #title>
        <el-icon><ArtIcon :name="m.icon" /></el-icon>
        <span>{{ m.title }}</span>
      </template>
      <SidebarSubmenu :menus="m.children || []" />
    </el-sub-menu>

    <el-menu-item v-else :index="m.path" @mouseenter="prefetch(m.path)" @focus="prefetch(m.path)">
      <el-icon><ArtIcon :name="m.icon" /></el-icon>
      <template #title>{{ m.title }}</template>
    </el-menu-item>
  </template>
</template>

<script setup lang="ts">
/** 侧边栏菜单递归渲染：目录(type=1) 渲染为子菜单，菜单(type=2) 渲染为菜单项。 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import ArtIcon from '@/components/core/ArtIcon.vue'
import type { MenuNode } from '@/api/types'

const props = defineProps<{ menus: MenuNode[] }>()

const router = useRouter()
/** 已经预取过的路径：调用 loader 就够触发下载，重复调用没有收益 */
const prefetched = new Set<string>()

/**
 * 悬停 / 聚焦即预取该页面的 chunk。
 *
 * 页面 chunk 是按路由懒加载的，点菜单时才开始下载 —— 在 150ms RTT 的网络下，
 * 这一下就是 0.3~0.6s 的“点了没反应”。用户把鼠标移到菜单项到真正点下去，
 * 通常有 200~400ms 间隔，这段时间足够把 chunk 拿到手，进入页面就只剩渲染。
 * 失败不做任何提示：真正导航时 vue-router 会再加载一次。
 */
function prefetch(path: string | undefined): void {
  if (!path || prefetched.has(path)) {
    return
  }
  const record = router.resolve(path).matched.at(-1)
  const loader = record?.components?.default as unknown
  if (typeof loader !== 'function') {
    return
  }
  prefetched.add(path)
  void (loader as () => Promise<unknown>)().catch(() => {
    // 预取失败就允许下次重试
    prefetched.delete(path)
  })
}

/** 按钮节点(type=3)不进侧边栏 */
const items = computed(() => props.menus.filter((m) => m.type !== 3))

function hasChildren(m: MenuNode): boolean {
  return (m.children || []).some((c) => c.type !== 3)
}
</script>
