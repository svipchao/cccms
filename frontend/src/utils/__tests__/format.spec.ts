import { describe, expect, it } from 'vitest'
import { formatBytes } from '@/utils/format'

describe('formatBytes', () => {
  it('0 默认展示 0 B，可改为「未知」占位', () => {
    expect(formatBytes(0)).toBe('0 B')
    expect(formatBytes(0, { empty: '—' })).toBe('—')
  })

  it('负数 / 非数字按空值处理（避免出现 -1 B 这种噪音）', () => {
    expect(formatBytes(-5)).toBe('0 B')
    expect(formatBytes(Number.NaN)).toBe('0 B')
    expect(formatBytes(Number.POSITIVE_INFINITY)).toBe('0 B')
  })

  it('按 1024 进制换单位，并去掉无意义的小数 0', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(1024)).toBe('1 KB')
    expect(formatBytes(1536)).toBe('1.5 KB')
    expect(formatBytes(1024 * 1024)).toBe('1 MB')
    expect(formatBytes(1.5 * 1024 * 1024)).toBe('1.5 MB')
    expect(formatBytes(1024 ** 3)).toBe('1 GB')
  })

  it('备份页需要的 GB 级与自定义小数位', () => {
    expect(formatBytes(1024 ** 4)).toBe('1 TB')
    expect(formatBytes(1234, { digits: 2 })).toBe('1.21 KB')
  })
})
