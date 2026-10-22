import { fileURLToPath, URL } from 'node:url'
import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import ElementPlus from 'unplugin-element-plus/vite'

/**
 * RemixIcon 全量字体只产出 woff2。
 *
 * remixicon.css 的 @font-face 挂了 eot / woff / ttf / svg 四套备用格式，Vite 会把
 * css 里出现的每个 url() 都复制进 dist —— 产物里凭空多出 4.2 MB
 * （svg 3.0MB + eot 613KB + ttf 613KB + woff 260KB），而 2016 年之后的浏览器
 * 一律走 woff2，这几个文件永远不会被请求。
 *
 * 在 generateBundle 阶段删文件 + 从 css 里摘掉对应 url()：
 * 比在 transform 里改源码可靠（css 会被 vite:css 再加工，改源码容易失效）。
 */
function remixiconWoff2Only(): Plugin {
  return {
    name: 'cccms:remixicon-woff2-only',
    apply: 'build',
    generateBundle(_options, bundle) {
      const dead = new Set<string>()
      for (const file of Object.keys(bundle)) {
        if (/remixicon.*\.(eot|ttf|svg|woff)$/i.test(file)) {
          dead.add(file.split('/').pop() as string)
          delete bundle[file]
        }
      }
      if (dead.size === 0) {
        return
      }

      for (const [file, item] of Object.entries(bundle)) {
        if (item.type !== 'asset' || !file.endsWith('.css')) {
          continue
        }
        let css = String(item.source)
        // 1) `src: url(xxx.eot);` 这类独立声明整条删掉
        css = css.replace(/\bsrc:\s*url\([^)]*\.eot[^)]*\)\s*;?/gi, '')
        // 2) `, url(xxx.woff) format("woff")` 这类并列分支连同前导逗号删掉
        for (const name of dead) {
          const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
          css = css.replace(new RegExp(`,\\s*url\\([^)]*${escaped}[^)]*\\)(\\s*format\\([^)]*\\))?`, 'gi'), '')
        }
        item.source = css
      }
    },
  }
}

export default defineConfig({
  base: '',
  plugins: [
    vue(),
    tailwindcss(),
    // Element Plus 按需引入：
    //  - Components 负责模板里的 <el-xxx> 与 v-loading 等指令，按需注册 + 注入该组件 CSS
    //  - ElementPlus 负责源码里 `import { ElMessage } from 'element-plus'` 这类
    //    命令式 API 的样式注入（去掉全量 index.css 后，靠它保证弹窗/提示有样式）
    Components({
      resolvers: [ElementPlusResolver({ importStyle: 'css' })],
      // 不生成 components.d.ts：模板不依赖全局类型声明，避免动 tsconfig
      dts: false,
    }),
    ElementPlus({}),
    remixiconWoff2Only(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      // FastAPI 的接口本身就挂在 /api 下，直接透传，不做 rewrite
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      // 附件（本地存储）也代理到后端：缩略图 <img src="/storage/..."> 与「查看」新窗口
      // 都从 5173 发请求，若不代理，Vite 会把 /storage 当 SPA 路由回退到 index.html，
      // 于是「查看」跳到 #/dashboard。生产由 Nginx 托管/反代（见 02-安装部署 §5）。
      '/storage': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    // 单 chunk 告警阈值：按需引入后单个业务 chunk 都在 100KB 级，
    // 这里留 700KB 的余量，超了就说明又有人整包 import 了大依赖（如 element-plus / echarts）。
    chunkSizeWarningLimit: 700,
    rollupOptions: {
      output: {
        /**
         * 只给 vue 运行时分一块固定 chunk（长期不变，利于缓存）。
         *
         * ⚠️ 不要给 element-plus / echarts 写 manualChunks：
         * 一旦某个模块被 manualChunks 指定了固定 chunk，Rollup 会把它当成 chunk 入口
         * **强制保留**，于是 `import { ElMessage } from 'element-plus'` 背后的整个
         * barrel（109 个组件、946KB）全部进产物，按需引入彻底失效。
         * 实测：同一个入口，加 element-plus 规则 = 1.44MB，不加 = 60KB。
         *
         * 这两个库不写规则时会由 Vite 自动分包：
         *   - element-plus 被多个页面共享 → 自动进入公共 chunk
         *   - echarts 只被 dashboard 动态 import → 自动成为独立懒加载 chunk
         */
        manualChunks(id) {
          if (!id.includes('node_modules')) {
            return undefined
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
