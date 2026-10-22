import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { configUi, type AppSecurityConfig, type AppUiConfig, type AppWatermarkConfig } from '@/api/config'
import { applyDefaultLocale, hasLocalePref } from '@/locales'
import { useSettingStore } from './setting'

/** 安全相关公开开关的兜底值（渠道为空 = 登录页不展示找回入口） */
const FALLBACK_SECURITY: AppSecurityConfig = { reset_channels: [] }

/** 全局水印的兜底值（后台未配置时与后端默认值保持一致，默认关闭） */
const FALLBACK_WATERMARK: AppWatermarkConfig = {
  enabled: false,
  content: '{username}{newline}{time}',
  opacity: 12,
  angle: -22,
  font_size: 14,
  color: '#8c8c8c',
  gap_x: 180,
  gap_y: 140,
}

/** 取不到后台配置时的兜底值（保证界面永远能正常渲染） */
const FALLBACK: AppUiConfig = {
  system: { name: 'CCCMS', logo: '', icp: '', copyright: '', maintenance: false, notice: '' },
  ui: { theme_mode: 'light', theme_primary: '#2b6cff', page_size: 15, tags_view: true, container_width: 0 },
  security: FALLBACK_SECURITY,
  watermark: FALLBACK_WATERMARK,
  locale: 'zh-CN',
  locales: ['zh-CN', 'en-US'],
}

/**
 * 内置 Logo：`frontend/public/logo.png`，构建时随 dist 拷贝到产物根目录。
 *
 * 后台 `system.logo` 留空时用它 —— 所以默认就有 Logo，不必先去配置管理里填 URL。
 *
 * 用**相对路径**（与 index.html 里的 `./logo.png` 同口径）：前端可能被部署在子目录下
 * （例如 `public/dist/` → 访问 /dist/index.html），写死 `/logo.png` 会指向站点根而 404。
 * 相对路径按文档 URL 解析，放根目录（/logo.png）和放子目录（/dist/logo.png）都对。
 */
const DEFAULT_LOGO = './logo.png'

/**
 * 上次成功下发的配置的本地缓存键。
 *
 * 用于 `hydrate()` 在挂载前同步回填 —— 把「首屏必须等一个配置接口」变成
 * 「首屏用上次的配置，接口回来后再校正」。
 */
const CACHE_KEY = 'cccms_app_config'

/**
 * 品牌与 UI 默认值（来自 sys_config 的 system.* / ui.* 分组）。
 *
 * 启动时拉一次，用于：系统名称、Logo、备案号、版权、维护公告、
 * 默认主题（新用户）、默认分页条数、多标签页开关、全局水印。
 */
export const useAppStore = defineStore('app', () => {
  const config = ref<AppUiConfig>({
    system: { ...FALLBACK.system },
    ui: { ...FALLBACK.ui },
    security: { ...FALLBACK_SECURITY },
    watermark: { ...FALLBACK_WATERMARK },
    locale: FALLBACK.locale,
    locales: FALLBACK.locales,
  })
  const loaded = ref(false)

  const systemName = computed(() => config.value.system.name || FALLBACK.system.name)
  /** 品牌 Logo：后台 `system.logo` 优先，留空回退内置 Logo（侧边栏 / 登录页共用） */
  const logo = computed(() => config.value.system.logo || DEFAULT_LOGO)
  const icp = computed(() => config.value.system.icp)
  const copyright = computed(() => config.value.system.copyright)
  const maintenance = computed(() => config.value.system.maintenance === true)
  const notice = computed(() => config.value.system.notice || '系统维护中，请稍后访问')

  const pageSize = computed(() => Number(config.value.ui.page_size) || FALLBACK.ui.page_size)
  const tagsView = computed(() => config.value.ui.tags_view !== false)

  /** 可用找回渠道：为空时登录页不展示「忘记密码」入口（后端按"开关 + 投递能力"判定，见 core/delivery.py） */
  const resetChannels = computed<string[]>(() => config.value.security?.reset_channels ?? [])

  /** 全局水印参数（后台「配置管理 → 水印」） */
  const watermark = computed<AppWatermarkConfig>(() => config.value.watermark ?? FALLBACK_WATERMARK)

  /** 把接口返回合并到兜底值之上（保持字段完整，避免后端少下发某组时界面炸掉） */
  function merge(res: AppUiConfig): AppUiConfig {
    return {
      system: { ...FALLBACK.system, ...(res.system ?? {}) },
      ui: { ...FALLBACK.ui, ...(res.ui ?? {}) },
      security: { ...FALLBACK_SECURITY, ...(res.security ?? {}) },
      watermark: { ...FALLBACK_WATERMARK, ...(res.watermark ?? {}) },
      locale: res.locale || FALLBACK.locale,
      locales: res.locales?.length ? res.locales : FALLBACK.locales,
    }
  }

  /**
   * 同步回填上次成功下发的配置（stale-while-revalidate 的 stale 部分）。
   *
   * 目的是让 `app.mount()` **不必等任何请求**：品牌名 / Logo / 主题 / 水印
   * 这类纯装饰数据，用上次的缓存就够渲染出正确界面，随后 `load()` 再静默校正。
   * 首次访问（无缓存）时什么也不做，直接用内置兜底值。
   */
  function hydrate(): void {
    try {
      const raw = localStorage.getItem(CACHE_KEY)
      if (!raw) {
        return
      }
      config.value = merge(JSON.parse(raw) as AppUiConfig)
      // 缓存里已经带了默认主题：提前应用，避免首屏闪一下再变色
      useSettingStore().applySystemDefaults(config.value.ui)
      if (!hasLocalePref() && config.value.locale) {
        applyDefaultLocale(config.value.locale)
      }
    } catch {
      // 缓存损坏 / localStorage 不可用：忽略，走兜底值
    }
  }

  async function load(): Promise<void> {
    try {
      const res = await configUi()
      if (res) {
        config.value = merge(res)
        try {
          localStorage.setItem(CACHE_KEY, JSON.stringify(config.value))
        } catch {
          // localStorage 不可用（隐私模式 / 配额满）：不影响本次会话
        }
      }
    } catch {
      // 拿不到后台配置就用兜底值，绝不阻塞启动
    } finally {
      loaded.value = true
      // 首次访问（本机无偏好）时，把后台下发的主题与默认语言作为默认值
      useSettingStore().applySystemDefaults(config.value.ui)
      if (!hasLocalePref() && config.value.locale) {
        applyDefaultLocale(config.value.locale)
      }
    }
  }

  return {
    config,
    loaded,
    systemName,
    logo,
    icp,
    copyright,
    maintenance,
    notice,
    pageSize,
    tagsView,
    resetChannels,
    watermark,
    hydrate,
    load,
  }
})
