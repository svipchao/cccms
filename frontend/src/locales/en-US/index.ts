/**
 * English language pack aggregate.
 *
 * **Grouped by origin**: one subdirectory per plugin, never dumped flat in this folder:
 * ```
 * en-US/
 *   core/    framework-level text: layout, router, table, settings, error pages… (shared)
 *   cccms/   text owned by the cccms plugin
 *   <plugin>/ add one more subdirectory per plugin
 * ```
 *
 * **Namespace = directory path** (`cccms/user.ts` → `cccms.user`), so plugins are
 * isolated by construction — two plugins can both ship a `user.ts` without clashing.
 * The trade-off is that call sites spell the full path:
 * `t('cccms.user.name')`, `t('core.common.back')`.
 *
 * **Auto-loaded**: a recursive `import.meta.glob` collects every `*.ts` under this
 * directory (skipping `index.ts` at any depth), so new packs need no manual registration.
 *
 * Keep the `zh-CN` / `en-US` directories' **relative paths and key sets identical**
 * (`npm run i18n:check` verifies this in CI).
 *
 * Backend-provided menu titles and config item names are not part of the frontend packs
 * (the backend translates them for the current request locale).
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

  // Build directory nodes level by level: {'cccms': {'user': {...}}}
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
