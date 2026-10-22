// 样式顺序有讲究：Tailwind(含 preflight) → Element Plus(按需) → 覆盖层 → 业务全局
import '@/assets/styles/index.css'
import 'element-plus/theme-chalk/dark/css-vars.css'
import 'remixicon/fonts/remixicon.css'
import '@/assets/styles/element.css'
import '@/assets/styles/app.css'
import 'nprogress/nprogress.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { i18n } from './locales'
import { setupDirectives } from './directives'
import { useAppStore } from './stores/app'
import { useSettingStore } from './stores/setting'
import { getToken } from './utils/auth'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
// Element Plus 不再 `app.use(ElementPlus)`、也不引 `dist/index.css`：
// 组件、指令与样式全部由 vite.config.ts 里的按需插件注入（见那里的注释）。
// 顺带解决了原先「installer 写入全局配置后 ElConfigProvider 无法再注入 locale」的坑：
// 现在全局配置里没有任何内容，语言包 / zIndex 只由 App.vue 的 ElConfigProvider 下发。
app.use(i18n)
setupDirectives(app)
app.use(router)

// 先用本地/内置默认值应用主题，避免白屏闪烁
useSettingStore(pinia).init()

const appStore = useAppStore(pinia)
// 再用「上次成功下发的配置」同步回填一次（首次访问无缓存，直接用内置兜底值）
appStore.hydrate()

/**
 * 关键：**挂载不等任何请求**。
 *
 * 系统名称 / Logo / 主题 / 水印这些配置只影响外观，用缓存或兜底值渲染出来的界面
 * 已经是完整可用的；接口慢 500ms 或直接超时，都不该让用户对着 loading 白屏。
 * 原先这里是 `Promise.race([load(), 2500ms])` → `mount()`，LCP 被一个装饰性接口
 * 硬控 0~2.5s，是首屏最大的单点延迟。
 */
app.mount('#app')
void appStore.load()

/**
 * 首屏 loader 等「首次导航完成」再撤。
 *
 * 挂载已经不再等任何请求，但路由守卫里还有「拉登录用户 + 拉菜单 + 下载页面 chunk」，
 * 如果这时 loader 就先消失，用户看到的是白屏而不是品牌加载态。所以：
 * 挂载照旧立即执行（守卫是在挂载后才开始跑的），loader 跟随 `router.isReady()` 收起；
 * 兜底 8 秒，任何异常都不会让 loading 永远盖在页面上。
 */
void Promise.race([
  router.isReady(),
  new Promise<void>((resolve) => {
    window.setTimeout(resolve, 8000)
  }),
]).finally(hideFirstPaintLoader)

registerServiceWorker()

// 空闲时预取下一个最可能进入的页面（工作台是登录后的默认落地页）
prefetchDashboardChunk()

/** 移除 index.html 里的内联首屏 loading */
function hideFirstPaintLoader(): void {
  const loader = document.getElementById('cccms-loader')
  if (!loader) {
    return
  }
  loader.classList.add('is-hide')
  window.setTimeout(() => loader.remove(), 300)
}

/**
 * 注册 Service Worker（仅生产）。
 *
 * 只做一件事：给带内容哈希的 /assets/* 做 cache-first。这些文件名里带 hash，
 * 内容永不变化，可以永久缓存；HTML 不进缓存，所以升级后一定会拿到新的 hash 清单，
 * 不存在「用户卡在旧版本」的风险。首次访问帮不上忙，重复访问 LCP 直接省掉全部资源请求。
 */
function registerServiceWorker(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
    return
  }
  // 相对路径：前端可能部署在子目录（如 /dist/），scope 自动跟随 sw.js 所在目录
  const url = './sw.js'
  window.addEventListener('load', () => {
    void navigator.serviceWorker.register(url).catch(() => {
      // 注册失败（非 HTTPS / 被策略禁用）不影响任何功能
    })
  })
}

/** 登录状态下空闲预取工作台 chunk：把「点登录 → 等工作台代码」的等待提前吃掉 */
function prefetchDashboardChunk(): void {
  if (!getToken()) {
    return
  }
  const run = (): void => {
    void import('@/pages/cccms/dashboard/index.vue').catch(() => {
      // 预取失败无所谓：真正导航时路由会再加载一次
    })
  }
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 3000 })
  } else {
    window.setTimeout(run, 1500)
  }
}
