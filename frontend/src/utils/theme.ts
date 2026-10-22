/**
 * 主题运行时：把用户的主题配置写进 CSS 变量。
 *
 * 为什么主色要算一遍：Element Plus 的 `--el-color-primary-light-3/5/7/8/9`
 * 与 `dark-2` 是**编译期**由主色派生出来的，运行时改主色不会自动更新，
 * 所以这里自己按「与背景色混合」重新推导。
 */

export type ThemeMode = 'light' | 'dark' | 'auto'

export interface ThemeConfig {
  /** 亮色 / 暗色 / 跟随系统 */
  mode: ThemeMode
  /** 品牌主色（hex） */
  primary: string
  /** 基础圆角（px） */
  radius: number
  /** 内容区最大宽度（px），0 表示占满 */
  containerWidth: number
}

export interface PresetColor {
  name: string
  value: string
}

/** 预设主色 */
export const PRESET_COLORS: PresetColor[] = [
  { name: '拂晓蓝', value: '#2b6cff' },
  { name: '极客蓝', value: '#1677ff' },
  { name: '明青', value: '#13c2c2' },
  { name: '极光绿', value: '#21c26b' },
  { name: '日暮黄', value: '#faad14' },
  { name: '火山橙', value: '#fa541c' },
  { name: '薄暮红', value: '#f4524d' },
  { name: '酱紫', value: '#722ed1' },
]

/** 暗色模式下用于混合的底色，与 tokens.css 的 --art-body-bg 保持一致 */
const DARK_BASE = '#14161c'

/** Element Plus 的浅色阶：变量名 → 与背景混合的比例 */
const LIGHT_STEPS: Array<[string, number]> = [
  ['--el-color-primary-light-3', 0.3],
  ['--el-color-primary-light-5', 0.5],
  ['--el-color-primary-light-7', 0.7],
  ['--el-color-primary-light-8', 0.8],
  ['--el-color-primary-light-9', 0.9],
]

function hexToRgb(hex: string): [number, number, number] {
  const value = hex.replace('#', '').trim()
  const full =
    value.length === 3
      ? value
          .split('')
          .map((c) => c + c)
          .join('')
      : value.padEnd(6, '0').slice(0, 6)
  const num = Number.parseInt(full, 16)
  return [(num >> 16) & 255, (num >> 8) & 255, num & 255]
}

function rgbToHex(rgb: [number, number, number]): string {
  return `#${rgb.map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')}`
}

/** 把 color 按 weight 比例向 target 混合（weight 为 target 的占比，0~1） */
export function mixColor(color: string, target: string, weight: number): string {
  const from = hexToRgb(color)
  const to = hexToRgb(target)
  return rgbToHex([
    from[0] + (to[0] - from[0]) * weight,
    from[1] + (to[1] - from[1]) * weight,
    from[2] + (to[2] - from[2]) * weight,
  ])
}

/** 计算最终生效的亮暗（auto 取系统偏好） */
export function resolveMode(mode: ThemeMode): 'light' | 'dark' {
  if (mode !== 'auto') {
    return mode
  }
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

/**
 * 应用主色。
 * 亮色模式向白色混合、暗色模式向暗底混合，这样两套背景下的对比度都正常。
 */
export function applyPrimary(color: string, mode: 'light' | 'dark'): void {
  const el = document.documentElement
  const base = mode === 'dark' ? DARK_BASE : '#ffffff'

  el.style.setProperty('--art-primary', color)
  el.style.setProperty('--el-color-primary', color)

  for (const [name, weight] of LIGHT_STEPS) {
    el.style.setProperty(name, mixColor(color, base, weight))
  }

  // dark-2 用于 hover/active：亮色下压暗，暗色下提亮
  el.style.setProperty(
    '--el-color-primary-dark-2',
    mode === 'dark' ? mixColor(color, '#ffffff', 0.2) : mixColor(color, '#000000', 0.2),
  )

  // 主色直接当**文字色**时对比度不够（白底 4.48:1、暗底 3.68:1，都低于 AA 的 4.5:1），
  // 因此单独派生一个文字档：亮色压暗、暗色提亮。填充色仍用上面的 --art-primary。
  el.style.setProperty(
    '--art-primary-text',
    mode === 'dark' ? mixColor(color, '#ffffff', 0.4) : mixColor(color, '#000000', 0.2),
  )
}

/**
 * 语义色（必须与 tokens.css 里的填充色一致）。
 *
 * Element Plus 的 `-light-3/5/7/8/9` 是它在编译期按**自己的**色板算出来的。
 * 我们把 `--el-color-success` 之类的基色换成自己的值后，这些浅色阶仍停在 EP 的旧色相上
 * （EP 的成功绿偏黄、危险红偏粉），于是标签 / 提示条 / plain 按钮的底色与其边框、图标
 * 对不上色相。这里按与主色完全相同的规则，在运行时把浅色阶重算一遍。
 */
const SEMANTIC_COLORS: Array<[string, string]> = [
  ['success', '#21c26b'],
  ['warning', '#ff9f2e'],
  ['danger', '#f4524d'],
  ['error', '#f4524d'],
  ['info', '#8f98a8'],
]

function applySemanticColors(mode: 'light' | 'dark'): void {
  const el = document.documentElement
  const base = mode === 'dark' ? DARK_BASE : '#ffffff'

  for (const [name, color] of SEMANTIC_COLORS) {
    for (const [varName, weight] of LIGHT_STEPS) {
      el.style.setProperty(varName.replace('primary', name), mixColor(color, base, weight))
    }
    // dark-2 用于 hover / active：亮色下压暗，暗色下提亮（同主色）
    el.style.setProperty(
      `--el-color-${name}-dark-2`,
      mode === 'dark' ? mixColor(color, '#ffffff', 0.2) : mixColor(color, '#000000', 0.2),
    )
  }
}

/**
 * 应用圆角（同时联动 Element Plus 的控件圆角）。
 *
 * 控件圆角要**收一档**：同一个 10px 放在 10px 高的卡片上是克制的，
 * 放在 24px 高的紧凑按钮 / 标签上就过圆了（视觉发糊）。所以卡片继续用
 * 用户选的 `--art-radius`，控件封顶 8px。
 */
export function applyRadius(radius: number): void {
  const el = document.documentElement
  const control = Math.min(radius, 8)
  el.style.setProperty('--art-radius', `${radius}px`)
  // 小/大档位也要跟着走，否则调整圆角时小控件与弹窗（--art-radius-lg）原地不动
  el.style.setProperty('--art-radius-sm', `${Math.max(radius - 4, 0)}px`)
  el.style.setProperty('--art-radius-lg', `${radius + 4}px`)
  el.style.setProperty('--el-border-radius-base', `${control}px`)
  el.style.setProperty('--el-border-radius-small', `${Math.max(control - 2, 0)}px`)
}

/** 应用内容区最大宽度 */
export function applyContainerWidth(width: number): void {
  document.documentElement.style.setProperty('--art-container-width', width > 0 ? `${width}px` : '100%')
}

/** 切换过程中临时关掉 transition，避免整页颜色渐变动画造成的闪烁 */
function withoutTransition(run: () => void): void {
  const el = document.documentElement
  el.classList.add('theme-transition-off')
  run()
  requestAnimationFrame(() => {
    requestAnimationFrame(() => el.classList.remove('theme-transition-off'))
  })
}

/** 一次性应用整套主题 */
export function applyTheme(config: ThemeConfig): void {
  withoutTransition(() => {
    const mode = resolveMode(config.mode)
    document.documentElement.classList.toggle('dark', mode === 'dark')
    applyPrimary(config.primary, mode)
    applySemanticColors(mode)
    applyRadius(config.radius)
    applyContainerWidth(config.containerWidth)
  })
}
