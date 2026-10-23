/**
 * 字段级 encrypt 解密（AES-256-GCM，WebCrypto）。
 *
 * 后端密文格式：base64( iv(12) || tag(16) || cipher )
 * WebCrypto 要求：iv 单独传，密文与 tag 拼接为 cipher || tag
 *
 * ⚠️ **当前无调用方，这是已决策的现状**：`encrypt` 字段的处理口径是
 * 「后端解密 + 按数据权限 mask 下发」，密钥不下发到浏览器（见更新日志 · 明确不做）。
 * 保留本文件是给「确有前端解密需求」的场景留一个已对齐的格式实现；
 * 若要启用，必须先改那条决策，否则等于把密钥暴露给前端。
 */

function base64ToBytes(b64: string): Uint8Array {
  const bin = atob(b64)
  const buffer = new ArrayBuffer(bin.length)
  const bytes = new Uint8Array(buffer)
  for (let i = 0; i < bin.length; i++) {
    bytes[i] = bin.charCodeAt(i)
  }
  return bytes
}

function toSource(bytes: Uint8Array): BufferSource {
  return bytes as unknown as BufferSource
}

export async function decryptField(encoded: string, keyBase64: string): Promise<string> {
  const raw = base64ToBytes(encoded)
  const iv = raw.slice(0, 12)
  const tag = raw.slice(12, 28)
  const cipher = raw.slice(28)

  const data = new Uint8Array(new ArrayBuffer(cipher.length + tag.length))
  data.set(cipher, 0)
  data.set(tag, cipher.length)

  const key = await crypto.subtle.importKey('raw', toSource(base64ToBytes(keyBase64)), { name: 'AES-GCM' }, false, [
    'decrypt',
  ])
  const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: toSource(iv) }, key, toSource(data))
  return new TextDecoder().decode(plain)
}
