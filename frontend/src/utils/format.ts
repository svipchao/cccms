/**
 * 数值格式化（展示用）。
 *
 * 以前附件页与备份页各写一份 `formatSize`，口径还不一样：
 * 附件页 `0 → '0 B'`、备份页 `0 → '—'`（0 表示「大小未知」而不是 0 字节），
 * 且备份页支持 GB 而没有 TB。这里统一实现，差异只由调用方通过 `empty` 表达。
 */

export interface FormatBytesOptions {
  /** 0 / 非法值的展示文本：`'0 B'`（真的 0）或 `'—'`（未知） */
  empty?: string
  /** 小数位数上限，默认按单位自动取（B 取 0，KB 取 1，MB 以上取 2） */
  digits?: number
}

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as const

/** 把字节数格式化成人类可读单位（1024 进制） */
export function formatBytes(bytes: number, options: FormatBytesOptions = {}): string {
  const { empty = '0 B', digits } = options

  if (!Number.isFinite(bytes) || bytes <= 0) {
    return empty
  }

  let value = bytes
  let unitIndex = 0
  while (value >= 1024 && unitIndex < UNITS.length - 1) {
    value /= 1024
    unitIndex++
  }

  const precision = digits ?? (unitIndex === 0 ? 0 : unitIndex === 1 ? 1 : 2)
  // toFixed 会留下 "1.00 MB" 这样的尾巴，去掉无意义的 0 更清爽（"1 MB"）
  const text = value
    .toFixed(precision)
    .replace(/\.0+$/, '')
    .replace(/(\.\d*?)0+$/, '$1')

  return `${text} ${UNITS[unitIndex]}`
}
