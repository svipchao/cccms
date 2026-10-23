import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import prettier from 'eslint-config-prettier'

/**
 * ESLint 扁平配置（ESLint 10）。
 *
 * 分工：
 *   - ESLint 只管「可能出错的代码」（未使用变量、可疑写法、Vue 必要规则）；
 *   - 格式统一交给 Prettier（末尾的 `prettier` 配置会关掉所有与格式冲突的规则）。
 *
 * 刻意**不使用** `vue/flat/recommended`：它包含大量「模板换行 / 属性顺序」之类的
 * 排版规则，会和 Prettier 打架，也会在既有代码里刷出成百上千条噪音。
 * 这里只用 `flat/essential`（真正的 Vue 规则集）。
 */
export default tseslint.config(
  {
    ignores: ['dist/**', 'node_modules/**', 'public/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/essential'],
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: {
        parser: tseslint.parser,
        extraFileExtensions: ['.vue'],
        ecmaVersion: 'latest',
        sourceType: 'module',
      },
    },
  },
  {
    // 类型感知规则只跑纯 TS 文件（stores / composables / utils / api）。
    // 原因：.vue 需要 vue-eslint-parser + 项目服务，配置复杂且收益低 ——
    // 模板里的漏 await 由 `vue-tsc` 的 `noUnusedLocals` 与人工评审兜底。
    files: ['src/**/*.ts'],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    rules: {
      // 漏 await 的 Promise：以前只在运行时报 unhandled rejection，
      // 现在在 lint 阶段就提示（warn，避免阻塞既有代码的提交）
      '@typescript-eslint/no-floating-promises': 'warn',
    },
  },
  {
    rules: {
      // TypeScript 已经负责未定义标识符检查，避免重复且误报浏览器全局
      'no-undef': 'off',
      'no-empty': ['warn', { allowEmptyCatch: true }],

      // 组件名允许「插件:模块」这种带冒号的名字（等于菜单 slug）
      'vue/multi-word-component-names': 'off',
      'vue/no-v-html': 'off',

      // 项目里大量使用 any（后端返回结构），不强制
      '@typescript-eslint/no-explicit-any': 'off',
      '@typescript-eslint/no-unused-vars': [
        'warn',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_', caughtErrors: 'none' },
      ],
      '@typescript-eslint/ban-ts-comment': 'warn',
    },
  },
  prettier,
)
