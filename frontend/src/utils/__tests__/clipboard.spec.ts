import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { copyText } from '@/utils/clipboard'

describe('copyText', () => {
  const originalClipboard = navigator.clipboard
  let execCommand: ReturnType<typeof vi.fn>

  beforeEach(() => {
    execCommand = vi.fn(() => true)
    Object.defineProperty(document, 'execCommand', { value: execCommand, configurable: true, writable: true })
  })

  afterEach(() => {
    Object.defineProperty(navigator, 'clipboard', { value: originalClipboard, configurable: true })
    vi.restoreAllMocks()
  })

  it('安全上下文优先用 Clipboard API', async () => {
    const writeText = vi.fn(() => Promise.resolve())
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })

    await expect(copyText('hello')).resolves.toBe(true)
    expect(writeText).toHaveBeenCalledWith('hello')
    expect(execCommand).not.toHaveBeenCalled()
  })

  it('http 内网（无 navigator.clipboard）回退到临时 textarea', async () => {
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true })

    await expect(copyText('inner-net')).resolves.toBe(true)
    expect(execCommand).toHaveBeenCalledWith('copy')
    // 兜底用的 textarea 必须清理掉，否则每复制一次就往 body 里塞一个节点
    expect(document.body.querySelector('textarea')).toBeNull()
  })

  it('Clipboard API 抛错（权限被拒）时继续走兜底', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: () => Promise.reject(new Error('denied')) },
      configurable: true,
    })

    await expect(copyText('fallback')).resolves.toBe(true)
    expect(execCommand).toHaveBeenCalled()
  })

  it('两种方式都失败时返回 false（调用方据此提示失败）', async () => {
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true })
    execCommand.mockImplementation(() => false)

    await expect(copyText('nope')).resolves.toBe(false)
  })
})
