import { fileURLToPath, URL } from 'node:url'
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'
import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

/**
 * 打包体积预算（gzip）。
 *
 * 为什么需要：`chunkSizeWarningLimit` 只是**告警**，而且告警阈值被调高过一次，
 * 于是「体积涨了」这件事在 CI 里彻底不可见。这里改成硬上限：
 * 超出即构建失败，涨体积必须显式修改预算（等于在 PR 里讨论一次）。
 *
 * 预算按「全部 JS 的 gzip 总和」算，而不是单个 chunk：分包策略调整不该触发失败，
 * 用户真正下载的总量才是成本。可用 `BUNDLE_BUDGET_KB` 临时覆盖（本地分析用）。
 */
function bundleBudget(): Plugin {
  const budgetKb = Number(process.env.BUNDLE_BUDGET_KB ?? 980)

  return {
    name: 'cccms:bundle-budget',
    apply: 'build',
    closeBundle() {
      const dir = fileURLToPath(new URL('./dist/assets', import.meta.url))
      let total = 0
      const rows: string[] = []

      for (const name of readdirSync(dir)) {
        if (!name.endsWith('.js')) continue
        const gz = gzipSync(readFileSync(join(dir, name))).length
        total += gz
        rows.push(`    ${(gz / 1024).toFixed(1).padStart(7)} KB  ${name}`)
      }

      const totalKb = total / 1024
      console.log(`\n[体积预算] JS gzip 合计 ${totalKb.toFixed(1)} KB / 上限 ${budgetKb} KB\n${rows.join('\n')}`)

      if (totalKb > budgetKb) {
        throw new Error(
          `JS 体积 ${totalKb.toFixed(1)} KB 超过预算 ${budgetKb} KB。` +
            '请先确认增量是否必要（大依赖按需引入 / 路由懒加载），确需放宽时显式调高 vite.config.ts 里的预算。',
        )
      }
    },
  }
}

export default defineConfig({
  base: '',
  plugins: [vue(), tailwindcss(), bundleBudget()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      // 前端统一以 /api 前缀调用，转发到 Webman（去掉 /api）
      '/api': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
      },
      // 附件（本地存储）也代理到后端：缩略图 <img src="/storage/..."> 与「查看」新窗口
      // 都从 5173 发请求，若不代理，Vite 会把 /storage 当 SPA 路由回退到 index.html，
      // 于是「查看」跳到 #/dashboard。生产由 Nginx 托管/反代（见 02-安装部署 §5）。
      '/storage': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
      },
    },
  },
  build: {
    chunkSizeWarningLimit: 2000,
    rollupOptions: {
      output: {
        // 用函数式分包：echarts 是按需引入（echarts/core），写死模块名会匹配不到
        manualChunks(id) {
          if (!id.includes('node_modules')) {
            return undefined
          }
          if (id.includes('echarts') || id.includes('zrender')) {
            return 'echarts'
          }
          if (id.includes('element-plus')) {
            return 'element-plus'
          }
          if (id.includes('vue-router') || id.includes('pinia') || /node_modules[\\/]@?vue[\\/]/.test(id)) {
            return 'vue'
          }
          return undefined
        },
      },
    },
  },
})
