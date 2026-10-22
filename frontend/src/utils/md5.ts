/**
 * MD5（返回 32 位小写十六进制）。
 *
 * 为什么要自己实现：巨量的投放链接宏参数要求 `md5(时间戳 + 随机串)` 这类 32 位串
 * （docs-sem/巨量引擎 里创建单元/投放链接的 code 也是同一算法），
 * 而 Web Crypto **不支持 MD5**（只有 SHA 系），项目也没有引 crypto 依赖。
 *
 * 实现按 RFC 1321 写，正确性由公开测试向量钉住（空串 / abc / 长文本 / 中文）。
 */

/** 每轮左移位数（RFC 1321 的 S 表） */
const SHIFT = [
  7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 4,
  11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21,
]

/** K 表：floor(abs(sin(i+1)) * 2^32)，用公式算而不是抄 64 个常量 */
const TABLE = (() => {
  const table = new Uint32Array(64)
  for (let i = 0; i < 64; i++) {
    table[i] = Math.floor(Math.abs(Math.sin(i + 1)) * 4294967296)
  }

  return table
})()

export function md5(input: string): string {
  const bytes = new TextEncoder().encode(input)
  const bitLength = bytes.length * 8

  // 补位：先补 0x80，再补 0x00 到「长度 ≡ 56 (mod 64)」，最后补 8 字节小端比特长度
  const padded = new Uint8Array((((bytes.length + 8) >> 6) + 1) * 64)
  padded.set(bytes)
  padded[bytes.length] = 0x80

  const view = new DataView(padded.buffer)
  // 低 32 位放在前 4 字节（小端）；高 32 位保持 0（< 512MB 的输入够用了）
  view.setUint32(padded.length - 8, bitLength >>> 0, true)

  let a0 = 0x67452301
  let b0 = 0xefcdab89
  let c0 = 0x98badcfe
  let d0 = 0x10325476
  const words = new Uint32Array(16)

  for (let offset = 0; offset < padded.length; offset += 64) {
    for (let i = 0; i < 16; i++) {
      words[i] = view.getUint32(offset + i * 4, true)
    }

    let a = a0
    let b = b0
    let c = c0
    let d = d0

    for (let i = 0; i < 64; i++) {
      let f: number
      let g: number
      if (i < 16) {
        f = (b & c) | (~b & d)
        g = i
      } else if (i < 32) {
        f = (d & b) | (~d & c)
        g = (5 * i + 1) % 16
      } else if (i < 48) {
        f = b ^ c ^ d
        g = (3 * i + 5) % 16
      } else {
        f = c ^ (b | ~d)
        g = (7 * i) % 16
      }

      const sum = (f + a + TABLE[i] + words[g]) | 0
      a = d
      d = c
      c = b
      b = (b + ((sum << SHIFT[i]) | (sum >>> (32 - SHIFT[i])))) | 0
    }

    a0 = (a0 + a) | 0
    b0 = (b0 + b) | 0
    c0 = (c0 + c) | 0
    d0 = (d0 + d) | 0
  }

  return [a0, b0, c0, d0].map(littleEndianHex).join('')
}

/** 32 位整数按小端序输出为 8 位十六进制 */
function littleEndianHex(value: number): string {
  let out = ''
  for (let i = 0; i < 4; i++) {
    out += ((value >>> (i * 8)) & 0xff).toString(16).padStart(2, '0')
  }

  return out
}
