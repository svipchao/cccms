import { http } from './request'

/**
 * 生成器可用性（后端 `GeneratorLogic::environment()`）。
 *
 * `allowed` 为 false 时页面只做提醒、不请求表结构；后端写操作同样会被 403 拦住。
 */
export interface GeneratorEnvironment {
  /** dev = 本地开发环境；production = 已构建的部署形态 */
  key: 'dev' | 'production'
  allowed: boolean
  /** 哪道闸没过：environment = 不是本地开发环境；switch = 总开关关闭 */
  blocked_by: '' | 'environment' | 'switch'
  /** 被拦截的原因（后端按当前语言返回） */
  reason: string
  /** 判定「开发环境」用的标记文件（相对仓库根） */
  marker: string
}

export interface TableInfo {
  name: string
  comment: string
}

export interface ColumnInfo {
  name: string
  type: string
  comment: string
  primary: boolean
}

export interface GeneratedFile {
  path: string
  content: string
}

export interface GenerateResult {
  files: string[]
  menu: { slug: string; title: string; path: string; component: string }
  menu_snippet: string
}

export function generatorStatus() {
  return http.get<GeneratorEnvironment>('/generator/status')
}

export function generatorTables() {
  return http.get<TableInfo[]>('/generator/tables')
}

export function generatorColumns(table: string) {
  return http.get<ColumnInfo[]>('/generator/columns', { table })
}

export function generatorPreview(config: Record<string, unknown>) {
  return http.post<GeneratedFile[]>('/generator/preview', config)
}

export function generatorGenerate(config: Record<string, unknown>) {
  return http.post<GenerateResult>('/generator/generate', config)
}
