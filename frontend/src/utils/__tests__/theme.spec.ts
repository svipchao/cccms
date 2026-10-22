import { beforeEach, describe, expect, it } from 'vitest'
import { applyPrimary, applyRadius, applyTheme, mixColor } from '@/utils/theme'

const cssVar = (name: string): string => document.documentElement.style.getPropertyValue(name)

const light = { mode: 'light' as const, primary: '#2b6cff', radius: 10, containerWidth: 0 }
const dark = { mode: 'dark' as const, primary: '#2b6cff', radius: 10, containerWidth: 0 }

describe('运行时主题派生', () => {
  beforeEach(() => {
    document.documentElement.removeAttribute('style')
    document.documentElement.classList.remove('dark')
  })

  it('亮色下按「向白混合」派生语义色浅色阶', () => {
    applyTheme(light)

    // 语义色的 -light-9 必须来自我们自己的色板，而不是 Element Plus 编译期的旧色相
    expect(cssVar('--el-color-success-light-9')).toBe(mixColor('#21c26b', '#ffffff', 0.9))
    expect(cssVar('--el-color-danger-light-5')).toBe(mixColor('#f4524d', '#ffffff', 0.5))
    expect(cssVar('--el-color-warning-dark-2')).toBe(mixColor('#ff9f2e', '#000000', 0.2))
  })

  it('暗色下浅色阶向暗底混合，文字档反向提亮', () => {
    applyTheme(dark)

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(cssVar('--el-color-success-light-9')).toBe(mixColor('#21c26b', '#14161c', 0.9))
    // 暗底上主色当文字只有 3.68:1，所以文字档是「向白混合」的亮度
    expect(cssVar('--art-primary-text')).toBe(mixColor('#2b6cff', '#ffffff', 0.4))
    expect(cssVar('--art-primary')).toBe('#2b6cff')
  })

  it('主色当文字时压暗一档（亮色），填充色保持原样', () => {
    applyPrimary('#2b6cff', 'light')

    expect(cssVar('--art-primary')).toBe('#2b6cff')
    expect(cssVar('--art-primary-text')).toBe(mixColor('#2b6cff', '#000000', 0.2))
  })

  it('控件圆角封顶 8px，卡片保留用户选择', () => {
    applyRadius(14)

    expect(cssVar('--art-radius')).toBe('14px')
    expect(cssVar('--el-border-radius-base')).toBe('8px')
    expect(cssVar('--el-border-radius-small')).toBe('6px')
  })

  it('内容区宽度为 0 时占满', () => {
    applyTheme({ ...light, containerWidth: 0 })
    expect(cssVar('--art-container-width')).toBe('100%')

    applyTheme({ ...light, containerWidth: 1600 })
    expect(cssVar('--art-container-width')).toBe('1600px')
  })
})
