/**
 * 简体中文语言包聚合（默认语言 / fallback）。
 *
 * **按来源分目录**，一个插件一个子目录，不要全部堆在本层：
 * ```
 * zh-CN/
 *   core/    框架级文案：布局、路由、表格、设置、错误页…（所有插件共用）
 *   cccms/   cccms 插件自己的文案
 *   <plugin>/ 后续插件照此新增一个同名子目录
 * ```
 *
 * **命名空间 = 目录路径**（`cccms/user.ts` → `cccms.user`），因此不同插件天然隔离，
 * 永远不会出现「两个插件都有 `user.ts`」的冲突；代价是业务代码里要写全路径：
 * `t('cccms.user.name')`、`t('core.common.back')`。
 *
 * **自动加载**：递归 `import.meta.glob` 收集本目录下所有 `*.ts`（跳过任意层级的 `index.ts`），
 * 新增语言包无需再手动 import / 注册。
 *
 * 只需保证 `zh-CN` / `en-US` 两个目录的**相对路径与 key 集合完全一致**
 * （`npm run i18n:check` 在 CI 里校验）。
 *
 * 后端下发的菜单标题、配置项名等不属于前端语言包（由后端按当前请求语言翻译）。
 */
const modules = import.meta.glob<{ default: Record<string, unknown> }>('./**/*.ts', { eager: true })

const messages: Record<string, Record<string, unknown>> = {}
for (const [path, mod] of Object.entries(modules)) {
  // ./cccms/user.ts → ['cccms', 'user']
  const segments = path.replace(/^\.\//, '').replace(/\.ts$/, '').split('/')
  const leaf = segments.pop() as string
  if (leaf === 'index') {
    continue
  }

  // 逐级建目录节点，最后一级挂语言模块：{'cccms': {'user': {...}}}
  let node: Record<string, unknown> = messages
  for (const segment of segments) {
    const child = node[segment]
    if (typeof child !== 'object' || child === null) {
      node[segment] = {}
    }
    node = node[segment] as Record<string, unknown>
  }
  node[leaf] = mod.default
}

export default messages
