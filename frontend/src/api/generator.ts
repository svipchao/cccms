import { http } from './request'

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

interface PreviewResponse {
  module: string
  files: Record<string, string>
  menu_snippet: string
}

interface GenerateResponse {
  module: string
  written: string[]
  skipped: string[]
  menu_snippet: string
}

export function generatorTables() {
  return http.get<TableInfo[]>('/generator/tables')
}

export function generatorColumns(table: string) {
  return http.get<ColumnInfo[]>('/generator/columns', { table })
}

export async function generatorPreview(config: Record<string, unknown>): Promise<GeneratedFile[]> {
  const data = await http.post<PreviewResponse>('/generator/preview', config)
  return Object.entries(data.files).map(([path, content]) => ({ path, content }))
}

export async function generatorGenerate(config: Record<string, unknown>): Promise<GenerateResult> {
  const data = await http.post<GenerateResponse>('/generator/generate', config)
  const module = data.module
  return {
    files: data.written,
    menu: {
      slug: `cccms:${module}:index`,
      title: String(config.title ?? module),
      path: `/${module}`,
      component: module,
    },
    menu_snippet: data.menu_snippet,
  }
}
