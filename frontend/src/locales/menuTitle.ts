import { i18n } from './index'
import { translateTitle } from './title'

/**
 * 菜单标题翻译：按菜单 `path` 查 `menuTitle` 语言包。
 *
 * 后端菜单表的 `title` 是中文原文（内置菜单与管理员自建菜单共用这一列），不会随
 * `Accept-Language` 变化，所以翻译放在渲染期做：
 *   - 内置菜单命中 `menuTitle.<path>` → 跟随语言切换（响应式）
 *   - 自建菜单未命中 → 交给 `translateTitle()`，兼容静态路由的 i18n key，其余原样展示
 */
export function translateMenuTitle(path: string | undefined, title: unknown): string {
  if (path) {
    const key = `menuTitle.${path}`
    if (i18n.global.te(key)) {
      return i18n.global.t(key) as string
    }
  }
  return translateTitle(title)
}
