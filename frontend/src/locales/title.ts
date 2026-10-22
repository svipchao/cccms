import { i18n } from './index'

/**
 * 翻译一个「可能是 i18n key、也可能是后端原文」的标题。
 *
 * 存在的理由：路由 / 标签页的 `title` 有两个来源 ——
 *   - 前端静态路由：`route.dashboard` 这类 key，需要翻译
 *   - 后端菜单树：中文原文（后端不按语言翻译菜单标题，见 `locales/menuTitle.ts`）
 *
 * 两种值混在同一个字段里，无法靠调用方区分，因此这里用 `te()` 探测：
 * key 存在就翻译，不存在就原样返回。这样后端新增的自定义菜单（用户录入数据）
 * 也能正常显示，不会被误判成缺词而顶出 key 原文。
 */
export function translateTitle(title: unknown): string {
  if (typeof title !== 'string' || title === '') {
    return ''
  }
  // te() 走 fallbackLocale 链：当前语言缺词但默认语言有，仍然算命中
  return i18n.global.te(title) ? (i18n.global.t(title) as string) : title
}
