/**
 * 复制文本到剪贴板。
 *
 * 为什么需要兜底：`navigator.clipboard` 只在**安全上下文**（https / localhost）可用，
 * 内网 http 部署时它是 undefined，直接调用会让「复制」按钮静默失败。
 *
 * 返回是否真正复制成功 —— 提示文案（成功 / 失败图标与措辞）由各页面决定。
 */
export async function copyText(text: string): Promise<boolean> {
  if (navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text)
      return true
    } catch {
      // 权限被拒 / 非聚焦文档时继续走兜底
    }
  }

  return legacyCopy(text)
}

/** 临时 textarea + execCommand：仅用于 http 内网等非安全上下文 */
function legacyCopy(text: string): boolean {
  const area = document.createElement('textarea')
  area.value = text
  // 移出视口但仍可选中，避免页面滚动跳动
  area.style.position = 'fixed'
  area.style.top = '-9999px'
  area.style.opacity = '0'
  area.setAttribute('readonly', '')

  document.body.appendChild(area)
  try {
    area.select()
    return document.execCommand('copy')
  } catch {
    return false
  } finally {
    document.body.removeChild(area)
  }
}
