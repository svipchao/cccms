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

/**
 * 覆盖率统计范围：`utils` / `composables` / `stores` / `api` 四类**纯逻辑目录**。
 *
 * 用通配而不是逐个列文件：以前是手写白名单，新建的 `utils/clipboard.ts`、
 * `composables/useRecycle.ts` 写了用例也不进统计，等于「测了但不算」。
 * 现在只要放进这四类目录、并且有用例加载它，就自动纳入分母。
 */
const COVERAGE_INCLUDE = ['src/utils/**/*.ts', 'src/composables/**/*.ts', 'src/stores/**/*.ts', 'src/api/**/*.ts']

/**
 * 显式排除的模块（都是「薄封装 / 依赖 DOM 与第三方实例」，单测只能断言框架行为）：
 *
 * - `crypto.ts`：WebCrypto 封装，格式由后端决定，当前无调用方（决策见更新日志）
 * - `progress.ts`：包 NProgress，断言它等于测第三方库
 * - `theme.ts` / `icon.ts`：直接改 CSS 变量与 DOM，浏览器行为由人工验收
 * - `api/types.ts`：纯类型声明，编译后没有可执行代码
 */
const COVERAGE_EXCLUDE = [
  'src/**/__tests__/**',
  'src/utils/crypto.ts',
  'src/utils/progress.ts',
  'src/utils/theme.ts',
  'src/utils/icon.ts',
  'src/api/types.ts',
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
       * 页面与组件不在统计范围内：`src/pages/**` / `src/components/**` / `src/layouts/**`
       * 的主链路回归由后端集成测试（鉴权矩阵 / 数据权限三档 / 回收站）+ 构建 + 人工验收覆盖
       * （前端 E2E 已在 [更新日志「明确不做」](../docs/更新日志.md) 中定案不引入）。
       */
      include: COVERAGE_INCLUDE,
      exclude: COVERAGE_EXCLUDE,
      /**
       * `all: false` —— 只统计**本次真正加载过**的文件。
       *
       * 默认的 `all: true` 会把 `include` 里「没被加载」的文件也补成 0% 行，而
       * Windows 上 `include` 的路径形式（盘符大写）与模块加载记录（盘符小写）对不上，
       * 于是每个文件都会多出一行恒为 0% 的重复统计，总覆盖率被直接腰斩 —— 本地
       * `test:coverage` 永远失败，CI（Linux）却通过，属于假信号。
       *
       * 代价是「在通配范围内、但没有任何用例 import 它」的模块不出现在报表里。
       * 该缺口由约定兜住：**新增纯逻辑模块时请同时补 `__tests__/*.spec.ts`**，
       * 否则它既没有回归保护、也不会出现在覆盖率里。
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
