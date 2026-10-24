<template>
  <div class="layout">
    <LayoutSidebar :collapsed="collapsed" :mobile="isMobile" />
    <div v-if="isMobile && !collapsed" class="layout-mask" @click="collapsed = true" />
    <div class="layout-main">
      <LayoutHeader v-model:collapsed="collapsed" />
      <!-- 多标签页开关来自后台配置 ui.tags_view -->
      <LayoutTagbar v-if="appStore.tagsView" />
      <LayoutContent />
    </div>
    <!-- 全局水印（后台「配置管理 → 水印」开启后叠加，不拦截交互） -->
    <Watermark />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMediaQuery } from '@vueuse/core'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import Watermark from '@/components/Watermark.vue'
import LayoutSidebar from './components/LayoutSidebar.vue'
import LayoutHeader from './components/LayoutHeader.vue'
import LayoutTagbar from './components/LayoutTagbar.vue'
import LayoutContent from './components/LayoutContent.vue'
import { useAppStore } from '@/stores/app'
import { useNoticeStore } from '@/stores/notice'
import { useUserStore } from '@/stores/user'
import { useWorktabStore } from '@/stores/worktab'
import { tokenJti, useRealtime } from '@/composables/useRealtime'
import { resetAfterLogout } from '@/router'

const { t } = useI18n({ useScope: 'global' })
const route = useRoute()
const router = useRouter()
const worktab = useWorktabStore()
const appStore = useAppStore()
const noticeStore = useNoticeStore()
const userStore = useUserStore()
const realtime = useRealtime()

/**
 * 实时推送（F9）在布局层启动：整个后台只连一条 SSE，与具体页面无关。
 *
 * 收不到也不影响可用性 —— 未读数、任务状态本来就有接口拉取，推送只负责「立刻」。
 */
onMounted(() => {
  realtime.connect()

  realtime.on('notice', () => {
    void noticeStore.refreshUnread()
  })

  realtime.on('logout', (data) => {
    const targetJti = String(data.jti ?? '')
    const mine = tokenJti(userStore.token)
    // 单会话踢人：只登出被踢的那个令牌，同账号在别的设备上的会话不受影响
    if (targetJti !== '' && mine !== '' && targetJti !== mine) {
      return
    }

    // 与手动登出走同一套清理：断连接（含退避定时器）、清标签页 / 动态路由 /
    // 未读角标 / 导出轮询。少做其中任何一步，登录页上都会留着上一个账号的残留。
    resetAfterLogout()
    void userStore.logout()
    ElMessage.warning(t('core.layout.kickedOffline'))
    void router.replace('/login')
  })
})

const isMobile = useMediaQuery('(max-width: 900px)')
const collapsed = ref(false)

/** 移动端默认收起为抽屉，切回桌面端自动展开 */
watch(
  isMobile,
  (value) => {
    collapsed.value = value
  },
  { immediate: true },
)

/** 路由变化 → 登记标签页（keep-alive 的 include 依据标签页生成） */
watch(
  () => route.path,
  () => {
    if (!route.name || route.name === 'notFound' || route.meta.public) {
      return
    }
    worktab.setActive(route.path)
    worktab.addTab({
      path: route.path,
      name: String(route.name),
      title: (route.meta.title as string) || String(route.name),
      icon: route.meta.icon as string | undefined,
    })
  },
  { immediate: true },
)
</script>

<style scoped>
.layout {
  display: flex;
  height: 100vh;
  overflow: hidden;
  background: var(--art-body-bg);
}

.layout-main {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.layout-mask {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgb(0 0 0 / 45%);
}
</style>
