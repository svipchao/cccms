/**
 * 语言包体检（CI 用）。
 *
 * 检查四件事，前两者是**硬性失败**，后两者只做提示：
 *  ① zh-CN / en-US 的**相对路径**（按插件分目录后即 `core/x.ts`、`cccms/y.ts`）必须完全一致
 *     —— 只在一侧存在的文件，等于整块命名空间缺失。
 *  ② 两个语言包拍平后的 key 集合必须完全一致
 *     —— 不一致时缺 key 的一侧会在界面上直接暴露 `user.xxx` 这样的 key 原文。
 *  ③ 扫描页面/组件里残留的硬编码中文（info）—— 用户录入数据与注释无法静态区分，
 *     所以只统计不判失败，避免出现「为了过 CI 而乱加 key」的反效果。
 *  ④ 统计「源码里没有字面量引用」的 key（info）—— 语言包只增不减，
 *     功能下线后残留的 key 会误导后续改文案；动态拼接的 key 会误报，故只提示。
 *
 * 目录约定（两端必须镜像）：`core/` 放框架级文案，`cccms/` 等子目录放各插件自己的文案；
 * 命名空间取**目录路径**（`cccms/user.ts` → `cccms.user`），因此不同插件的同名文件互不冲突。
 *
 * 语言包是 TS，Node 不能直接 import，这里用 esbuild 编译成 CJS 再加载。
 * esbuild 已显式声明在 devDependencies（版本与 vite 对齐）——不能靠「vite 的传递依赖」
 * 凑巧被提升上来，否则换包管理器或依赖树变化时脚本会直接 `Cannot find module 'esbuild'`。
 */

import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { build } from 'esbuild'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const LOCALES = path.join(ROOT, 'src/locales')
const DEFAULT_LOCALE = 'zh-CN'

/**
 * 把某个语言目录下的每个 `*.ts` 模块分别打包成 CJS 并求值，按「目录路径 = 命名空间」组装成语言包。
 *
 * 注意：`index.ts` 现在用 `import.meta.glob` 自动收集模块（Vite 专属语法），esbuild 不支持，
 * 所以这里不再打包 `index.ts`，而是直接对每个模块文件逐个打包求值 —— 口径与运行时的
 * 「目录路径 = 命名空间」（`cccms/user.ts` → `cccms.user`）完全一致。
 */
async function loadLocale(locale) {
  const files = await moduleFiles(path.join(LOCALES, locale))
  const messages = {}
  for (const file of files) {
    const result = await build({
      entryPoints: [path.join(LOCALES, locale, file)],
      bundle: true,
      format: 'cjs',
      platform: 'node',
      write: false,
      logLevel: 'silent',
    })

    const code = result.outputFiles[0].text
    const module = { exports: {} }
    new Function('module', 'exports', 'require', code)(module, module.exports, () => ({}))
    messages[file.replace(/\.ts$/, '').replace(/\//g, '.')] = module.exports.default ?? module.exports
  }
  return messages
}

/** 把嵌套语言包拍平成 `命名空间.键名` 的集合 */
function flatten(messages, prefix = '') {
  const keys = new Set()
  for (const [key, value] of Object.entries(messages)) {
    const full = prefix ? `${prefix}.${key}` : key
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      for (const nested of flatten(value, full)) {
        keys.add(nested)
      }
    } else {
      keys.add(full)
    }
  }
  return keys
}

/**
 * 递归列出语言目录下的模块文件（不含 index.ts），返回相对路径（`cccms/user.ts`）：
 * 按插件分子目录后，只有相对路径才能准确表达「两个语言目录结构是否镜像」。
 */
async function moduleFiles(dir, prefix = '') {
  const entries = await readdir(dir, { withFileTypes: true })
  const files = []
  for (const entry of entries) {
    const relative = prefix ? `${prefix}/${entry.name}` : entry.name
    if (entry.isDirectory()) {
      files.push(...(await moduleFiles(path.join(dir, entry.name), relative)))
    } else if (entry.isFile() && entry.name.endsWith('.ts') && entry.name !== 'index.ts') {
      files.push(relative)
    }
  }
  return files.sort()
}

/** 递归收集待扫描的源码文件（跳过测试目录） */
async function sourceFiles(dir, out = []) {
  let entries
  try {
    entries = await readdir(dir, { withFileTypes: true })
  } catch {
    return out
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === '__tests__') {
        continue
      }
      await sourceFiles(full, out)
    } else if (/\.(vue|ts)$/.test(entry.name) && !entry.name.endsWith('.d.ts')) {
      out.push(full)
    }
  }
  return out
}

/** 粗略统计一行的可见文案里是否含中文（排除注释行） */
const CHINESE = /[\u4e00-\u9fff]/

function countHardcodedChinese(source) {
  let hits = 0
  for (const rawLine of source.split('\n')) {
    const line = rawLine.trim()
    if (line.startsWith('//') || line.startsWith('*') || line.startsWith('/*')) {
      continue
    }
    // 模板里 {{ t('x') }} 之外的中文，以及作为属性值的整句中文
    if (CHINESE.test(line)) {
      hits++
    }
  }
  return hits
}

async function main() {
  const problems = []

  // ---- ① 文件级对齐（相对路径，含插件子目录） ----
  const [zhFiles, enFiles] = await Promise.all([
    moduleFiles(path.join(LOCALES, DEFAULT_LOCALE)),
    moduleFiles(path.join(LOCALES, 'en-US')),
  ])
  for (const name of zhFiles.filter((n) => !enFiles.includes(n))) {
    problems.push(`语言模块只存在于 ${DEFAULT_LOCALE}：${name}`)
  }
  for (const name of enFiles.filter((n) => !zhFiles.includes(n))) {
    problems.push(`语言模块只存在于 en-US：${name}`)
  }

  // ---- ② key 级对齐 ----
  const [zh, en] = await Promise.all([loadLocale(DEFAULT_LOCALE), loadLocale('en-US')])
  const zhKeys = flatten(zh)
  const enKeys = flatten(en)

  const missingInEn = [...zhKeys].filter((key) => !enKeys.has(key))
  const missingInZh = [...enKeys].filter((key) => !zhKeys.has(key))

  for (const key of missingInEn.slice(0, 20)) {
    problems.push(`en-US 缺少 key：${key}`)
  }
  for (const key of missingInZh.slice(0, 20)) {
    problems.push(`${DEFAULT_LOCALE} 缺少 key：${key}`)
  }

  // ---- ③ 硬编码中文（提示，不判失败） ----
  // composables / stores 里的 ElMessage 文案同样是用户可见的，别再漏掉
  const targets = [
    path.join(ROOT, 'src/pages'),
    path.join(ROOT, 'src/layouts'),
    path.join(ROOT, 'src/components'),
    path.join(ROOT, 'src/composables'),
    path.join(ROOT, 'src/stores'),
    path.join(ROOT, 'src/api'),
    path.join(ROOT, 'src/utils'),
  ]
  const files = (await Promise.all(targets.map((dir) => sourceFiles(dir)))).flat()
  const dirty = []
  /** 源码里出现过的字符串字面量（用于粗略判断语言包 key 是否还有人用） */
  const literals = new Set()
  for (const file of files) {
    const source = await readFile(file, 'utf8')
    const hits = countHardcodedChinese(source)
    if (hits > 0) {
      dirty.push({ file: path.relative(ROOT, file), hits })
    }
    for (const match of source.matchAll(/['"`]([A-Za-z][\w.-]{2,})['"`]/g)) {
      literals.add(match[1])
    }
  }
  dirty.sort((a, b) => b.hits - a.hits)

  // ---- ④ 未被引用的 key（提示，不判失败） ----
  // 语言包只增不减：功能下线后 key 留在库里，改文案时容易改到没人用的那一份。
  // 动态拼接（`t(\`${prefix}.all\`)`）会被误报成「未使用」，所以只做提示。
  const unusedKeys = [...zhKeys].filter((key) => !literals.has(key))

  // ---- 输出 ----
  console.log(`语言包：${DEFAULT_LOCALE} ${zhKeys.size} key，en-US ${enKeys.size} key`)
  if (problems.length === 0) {
    console.log('✓ 两语言命名空间与 key 集合完全一致')
  } else {
    console.error(`\n✗ 语言包不一致（共 ${problems.length} 处）：`)
    for (const problem of problems) {
      console.error(`  - ${problem}`)
    }
  }

  if (unusedKeys.length > 0) {
    console.log(
      `\n提示：${unusedKeys.length} 个 key 在源码中未发现字面量引用` +
        `（动态拼接的 key 会误报，仅供参考；确认无用后从两端语言包一起删）：`,
    )
    for (const key of unusedKeys.slice(0, 20)) {
      console.log(`  ${key}`)
    }
    if (unusedKeys.length > 20) {
      console.log(`  … 其余 ${unusedKeys.length - 20} 个省略`)
    }
  }

  if (dirty.length > 0) {
    console.log(`\n提示：以下文件仍有硬编码中文（含用户数据与注释，仅供参考，不影响结果）：`)
    for (const { file, hits } of dirty.slice(0, 15)) {
      console.log(`  ${String(hits).padStart(4)} 行  ${file}`)
    }
    if (dirty.length > 15) {
      console.log(`  … 其余 ${dirty.length - 15} 个文件省略`)
    }
  }

  process.exit(problems.length === 0 ? 0 : 1)
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
