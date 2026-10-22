/**
 * CCCMS 静态资源缓存（cache-first for hashed assets）
 *
 * 只做一件事：`/assets/*` 下带内容哈希的文件（`index-BCrr-RvC.js`、
 * `element-plus-DVkrAIy3.js` ...）命中缓存就直接返回，否则回源并写入缓存。
 *
 * 为什么这样是安全的：
 *   - 文件名里的 hash 由内容决定，同一文件名对应的字节永不变化，所以不需要
 *     失效策略；重新发布只会产生新文件名，旧条目由 activate 阶段清理。
 *   - **HTML 不进缓存**：index.html 始终走网络，因此发布后一定能拿到新的
 *     hash 清单，不存在「用户被旧版本锁死」的问题。
 *   - 不拦截 `/api/`、`/storage/`：接口与附件保持原有行为（含鉴权、Range 请求）。
 *
 * 收益：第二次及以后的访问，首屏 JS/CSS/字体全部命中本地缓存，
 * 不产生任何网络往返（配合 Nginx 的 immutable 头，效果等同于「瞬时首屏」）。
 */

const CACHE = 'cccms-assets-v1'

/** 命中条件：/assets/ 下且文件名带 8 位以上内容哈希 */
const HASHED_ASSET = /\/assets\/[^/]+-[A-Za-z0-9_-]{8,}\.(?:js|css|woff2?|png|jpe?g|svg|webp)$/

self.addEventListener('install', () => {
  // 立即接管，不必等所有标签页关闭
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys()
      await Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))
      await self.clients.claim()
    })(),
  )
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') {
    return
  }

  const url = new URL(request.url)
  if (url.origin !== self.location.origin || !HASHED_ASSET.test(url.pathname)) {
    return
  }

  event.respondWith(
    (async () => {
      const cache = await caches.open(CACHE)
      const hit = await cache.match(request)
      if (hit) {
        return hit
      }
      const response = await fetch(request)
      // 只缓存完整的 200 响应（跳过 206/304 与错误页）
      if (response.status === 200 && !response.headers.has('Content-Range')) {
        cache.put(request, response.clone()).catch(() => {})
      }
      return response
    })(),
  )
})
