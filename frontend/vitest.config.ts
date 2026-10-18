import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'

/**
 * Vitest 配置（P1-7）。
 *
 * 刻意**不合并 `vite.config.ts`**：那边挂着 `@vitejs/plugin-vue` 与 `@tailwindcss/vite`，
 * 单元测试的对象是纯函数与 composable，不需要编译 Vue 单文件组件、也不需要跑 Tailwind，
 * 带上它们只会拖慢启动。代价是 `@` 别名要在两处各写一次 —— 改动别名时记得同步。
 *
 * 环境用 jsdom：`useTable` 依赖 `localStorage` / `window.setTimeout`，
 * `request.ts` 的拦截器依赖 `window.location`。
 */
const srcDir = fileURLToPath(new URL('./src', import.meta.url))

/** 覆盖率统计的**核心模块**（相对项目根，见下方 `include` 的说明） */
const CORE_MODULES = [
  'src/utils/auth.ts',
  'src/utils/cron.ts',
  'src/utils/password.ts',
  'src/utils/richText.ts',
  'src/utils/trace.ts',
  'src/composables/useTable.ts',
  'src/stores/user.ts',
  'src/api/request.ts',
]

export default defineConfig({
  resolve: {
    alias: {
      '@': srcDir,
    },
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/__tests__/**/*.spec.ts'],
    // 每个用例文件独立环境，避免 localStorage / 模块级单例（如 axios 实例）互相污染
    isolate: true,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json-summary'],
      /**
       * 覆盖率统计范围 = **本批用例真正负责保护的模块**（显式列文件，不用通配）。
       *
       * 为什么不用 `src/utils/**` 这类通配把所有工具都纳进来：那样分母里会混进
       * `utils/crypto.ts`（WebCrypto 薄封装）、`utils/progress.ts`（包 NProgress）、
       * `utils/theme.ts` / `utils/icon.ts`（直接操作 DOM 与 CSS 变量），
       * 以及 `stores/{app,menu,notice,setting,worktab}.ts`、`composables/useRecycle.ts`
       * 这些依赖组件渲染 / 路由 / Element Plus 实例的模块。给它们写单测要么只是在
       * 断言框架行为，要么必须引入一堆 mock —— 收益低而维护成本高。
       * 页面与组件同理：`src/pages/**` 不计入，其主链路回归由后端集成测试
       * （鉴权矩阵 / 数据权限三档 / 回收站）+ 构建 + 人工验收覆盖
       * （前端 E2E 已在 [更新日志「明确不做」](../docs/更新日志.md) 中定案不引入）。
       *
       * 显式列文件的代价是「新增工具模块不会自动纳入」：因此**新增纯逻辑工具时，
       * 请把它加进这个列表并补用例**，否则等于没有回归保护。
       */
      include: CORE_MODULES,
      exclude: ['src/**/__tests__/**'],
      /**
       * `all: false` —— 只统计**本次真正加载过**的文件。
       *
       * 默认的 `all: true` 会把 `include` 里「没被加载」的文件也补成 0% 行，而
       * Windows 上 `include` 的路径形式（盘符大写）与模块加载记录（盘符小写）对不上，
       * 于是每个文件都会多出一行恒为 0% 的重复统计，总覆盖率被直接腰斩 —— 本地
       * `test:coverage` 永远失败，CI（Linux）却通过，属于假信号。
       *
       * 代价是「列在 `include` 里但一个用例都没覆盖」的模块不出现在报表里。
       * 该缺口由约定兜住：`CORE_MODULES` 里的每个模块都有对应 `__tests__/*.spec.ts`，
       * 新增核心模块时必须同时加进列表并补用例。
       */
      all: false,
      thresholds: {
        lines: 80,
        functions: 80,
        statements: 80,
        branches: 70,
      },
    },
  },
})
