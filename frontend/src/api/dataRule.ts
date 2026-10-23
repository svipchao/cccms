import { http, type ListRequestConfig } from './request'
import type { PageResult } from './types'

/**
 * 数据权限规则（sys_data_rule）。
 *
 * action：row = 行级过滤（用 operator/value 注入 where）；
 *         hidden / readonly / mask / encrypt = 字段级。
 * 绑定：user_id / post_id / role_id / dept_ids；组合方式由 bind_mode 决定
 *       （or = 任一命中，and = 已填写的全部命中）；全空 = 全局规则。
 */
export interface DataRuleRow {
  id: number
  name: string
  user_id: number
  post_id: number
  role_id: number
  dept_ids: number[]
  /** 绑定组合方式：or 任一命中 / and 全部命中 */
  bind_mode: 'or' | 'and'
  /** 后端补的组合方式中文名 */
  bind_mode_label?: string
  /** 目标表（不含前缀），空 = 不限表 */
  table_name: string
  field: string
  action: string
  operator: string
  value: string
  /** 取值类型：static 字面量 / dynamic 动态变量 */
  value_type: 'static' | 'dynamic'
  remark?: string
  /** 后端补的绑定名称，列表直接用 */
  user_name?: string
  post_name?: string
  role_name?: string
  dept_names?: string
  /** 目标表注释 */
  table_label?: string
  /** 规则体检结果（后端 RuleConflict 计算）：条件互斥 / 同字段多动作 / 恒不生效 / 表未受控 */
  conflicts?: { type: string; message: string }[]
  [key: string]: unknown
}

export interface DataRuleOption {
  id: number
  name?: string
  username?: string
  nickname?: string
  children?: DataRuleOption[]
}

/** 目标表候选（语义化名称取自库表注释） */
export interface DataRuleTable {
  /** 表名（不含前缀），提交用 */
  table: string
  /** 含前缀的完整表名，展示用 */
  full: string
  /** 表注释 */
  label: string
  fields: DataRuleField[]
}

export interface DataRuleField {
  field: string
  /** 字段注释 */
  label: string
  /** MySQL 数据类型，用于过滤操作符 */
  type: string
}

export interface DataRuleOptions {
  /** 默认空：用户改由 dataRuleUsers() 模糊搜索懒加载 */
  users: DataRuleOption[]
  posts: DataRuleOption[]
  roles: DataRuleOption[]
  depts: DataRuleOption[]
  tables: DataRuleTable[]
  /** 可用的动作与操作符（与后端校验同一份来源） */
  actions: string[]
  operators: string[]
  /** 操作符语义化名称：{ '=': '等于', like: '包含', ... } */
  operator_labels?: Record<string, string>
  /** 行级取值类型：['static', 'dynamic'] */
  value_types?: string[]
  /** 动态变量候选：[{ value: '{dept.subtree}', label: '我所属部门及其所有下级' }] */
  value_vars?: DataRuleVar[]
}

export interface DataRuleVar {
  value: string
  label: string
}

/* ---------- 受控表（哪些表可以配「自定义规则」；未登记的表不受影响，仍走预设基线） ---------- */

export interface DataScopeTableRow {
  id: number
  table_name: string
  label: string
  status: number
  remark: string
  field_count: number
  /** 库中是否还存在该表（表被删/改名时置 false，规则会暂停生效） */
  exists: boolean
}

export interface DataScopeTableAvailable {
  table: string
  full: string
  label: string
}

export interface DataScopeTableData {
  list: DataScopeTableRow[]
  available: DataScopeTableAvailable[]
}

export function dataRuleList(params: Record<string, unknown>, config?: ListRequestConfig) {
  return http.get<PageResult<DataRuleRow>>('/data_rule', params, config)
}

export function dataRuleOptions() {
  return http.get<DataRuleOptions>('/data_rule/options')
}

/**
 * 绑定用户候选：按关键词模糊搜索，默认无数据。
 * 传 ids 时按 id 精确取回（编辑回显已绑定的用户）。
 */
export function dataRuleUsers(params: { keyword?: string; ids?: number[] }) {
  return http.get<DataRuleOption[]>('/data_rule/users', params)
}

export function dataRuleSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/data_rule/save', data)
}

export function dataRuleUpdate(data: Record<string, unknown>) {
  return http.post<null>('/data_rule/update', data)
}

export function dataRuleDelete(id: number) {
  return http.post<null>('/data_rule/delete', { id })
}

/** 受控表列表 + 可加入的库表 */
export function dataScopeTableList() {
  return http.get<DataScopeTableData>('/data_rule/table')
}

export function dataScopeTableSave(data: Record<string, unknown>) {
  return http.post<{ id: number }>('/data_rule/table/save', data)
}

export function dataScopeTableUpdate(data: Record<string, unknown>) {
  return http.post<null>('/data_rule/table/update', data)
}

/** 移除受控表；返回该表上已配置的规则数（这些规则会暂停生效） */
export function dataScopeTableDelete(id: number) {
  return http.post<{ rules: number }>('/data_rule/table/delete', { id })
}
