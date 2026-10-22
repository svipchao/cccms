/**
 * 图标注册表：`sys_menu.icon` 里存的名字 → RemixIcon 字体类名。
 *
 * 图标统一改用 RemixIcon（`remixicon` 包，`<i class="ri-xxx-line">`）：
 * 类名是纯字符串，菜单图标存字符串即可直接映射，无需再维护「组件 → 名字」的注册表。
 * 名字保持 `icon-xxx` 形式，与 db/menu.php 中已有的配置兼容。
 *
 * 系统内置图标（表格 ArtTable、富文本编辑器 ArtRichEditor）仍用 element-plus 图标，不在此列。
 */
export const ICON_MAP: Record<string, string> = {
  'icon-settings': 'ri-settings-3-line',
  'icon-user': 'ri-user-line',
  'icon-safe': 'ri-lock-line',
  'icon-menu': 'ri-menu-line',
  'icon-tree': 'ri-building-2-line',
  'icon-badge': 'ri-id-card-line',
  'icon-book': 'ri-book-2-line',
  'icon-tool': 'ri-tools-line',
  'icon-file': 'ri-file-text-line',
  'icon-upload': 'ri-folder-open-line',
  'icon-clock': 'ri-time-line',
  'icon-code': 'ri-cpu-line',

  // 以下供设置面板 / 顶部栏等静态位置使用
  'icon-home': 'ri-home-5-line',
  'icon-briefcase': 'ri-briefcase-line',
  'icon-grid': 'ri-layout-grid-line',
  'icon-list': 'ri-list-check',
  'icon-monitor': 'ri-computer-line',
  'icon-bell': 'ri-notification-3-line',
  'icon-operation': 'ri-settings-4-line',
  'icon-management': 'ri-shield-user-line',
  'icon-magic': 'ri-magic-line',
  'icon-key': 'ri-key-2-line',
  'icon-timer': 'ri-timer-line',
  'icon-odometer': 'ri-speed-up-line',
  'icon-dataline': 'ri-line-chart-line',
  'icon-pie': 'ri-pie-chart-line',
  'icon-trend': 'ri-bar-chart-line',
  'icon-histogram': 'ri-bar-chart-box-line',
  'icon-tickets': 'ri-megaphone-line',
  'icon-refresh': 'ri-refresh-line',
  'icon-refresh-right': 'ri-refresh-line',
  'icon-fold': 'ri-menu-fold-line',
  'icon-expand': 'ri-menu-unfold-line',
  'icon-fullscreen': 'ri-fullscreen-line',
  'icon-search': 'ri-search-line',
  'icon-plus': 'ri-add-line',
  'icon-close': 'ri-close-line',
  'icon-moon': 'ri-moon-line',
  'icon-sunny': 'ri-sun-line',
  'icon-logout': 'ri-logout-box-r-line',
  'icon-arrow-down': 'ri-arrow-down-s-line',
  'icon-more': 'ri-more-2-fill',
  'icon-rank': 'ri-award-line',
  'icon-sort': 'ri-sort-asc',
  'icon-user-filled': 'ri-user-fill',
  'icon-edit': 'ri-edit-line',
  'icon-delete': 'ri-delete-bin-line',
  'icon-download': 'ri-download-2-line',
  'icon-picture': 'ri-image-line',
  'icon-check': 'ri-check-line',
  'icon-warning': 'ri-alert-line',
  'icon-view': 'ri-eye-line',
  'icon-hide': 'ri-eye-off-line',
  'icon-upload-filled': 'ri-upload-2-line',
}

/** 菜单图标候选（图标选择器用，排除纯功能性图标） */
export const MENU_ICON_OPTIONS: string[] = [
  'icon-settings',
  'icon-user',
  'icon-safe',
  'icon-menu',
  'icon-tree',
  'icon-badge',
  'icon-book',
  'icon-tool',
  'icon-file',
  'icon-upload',
  'icon-clock',
  'icon-code',
  'icon-home',
  'icon-briefcase',
  'icon-grid',
  'icon-list',
  'icon-monitor',
  'icon-bell',
  'icon-operation',
  'icon-management',
  'icon-magic',
  'icon-key',
  'icon-timer',
  'icon-odometer',
  'icon-dataline',
  'icon-pie',
  'icon-trend',
  'icon-histogram',
  'icon-tickets',
  'icon-picture',
  'icon-download',
  'icon-view',
]

/**
 * 按名字取 RemixIcon 类名；找不到时回退到菜单图标，避免渲染空白。
 * 传入的既可以是 `icon-xxx`（菜单存储值），也可以是直接的 `ri-xxx-line` 类名。
 */
export function resolveIcon(name?: string): string {
  if (!name) {
    return 'ri-menu-line'
  }
  if (name.startsWith('ri-')) {
    return name
  }
  const mapped = ICON_MAP[name] ?? ICON_MAP[`icon-${name}`]
  if (mapped) {
    return mapped
  }
  // 兼容库里直接按 RemixIcon 命名的值（seed 的 `icon-settings-3-line`、
  // 菜单管理里手填的 `icon-xxx`）：去掉前缀就是字体类名，不必每个都登记到 ICON_MAP。
  if (name.startsWith('icon-')) {
    return `ri-${name.slice('icon-'.length)}`
  }
  return 'ri-menu-line'
}
