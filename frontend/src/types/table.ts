/** 列表页公共类型 */
import type { PageResult } from '@/api/types'

export type { PageResult }

/** 列头筛选：枚举可选项 */
export interface ArtTableFilterOption {
  label: string
  /** 传给接口的原始值（多选时按逗号拼接） */
  value: string | number
}

/**
 * 列头筛选配置。
 *
 * - `enum`：列头弹层里的复选下拉框（可勾选多个选项，值按逗号拼接后发给接口）；
 * - `text`：列头弹层里的输入框，做模糊查询；
 * - `textarea`：多行输入框，用于请求参数 / 返回体这类 JSON 片段的关键字模糊查询；
 * - `date`：列头弹层里的日期范围选择器，分别写入起止两个字段（时间列专用）。
 */
export interface ArtTableColumnFilter {
  type: 'enum' | 'text' | 'textarea' | 'date'
  /** 枚举可选项（`type='enum'` 时必填） */
  options?: ArtTableFilterOption[]
  /** 写入 query 的字段名，默认取列的 `prop` */
  queryKey?: string
  /** 文本筛选输入框占位文案（`type='text'` / `'textarea'` 时可选） */
  placeholder?: string
  /** 多行输入框行数（`type='textarea'` 时可选，默认 3） */
  rows?: number
  /** 日期范围起始字段名（`type='date'` 时可选，默认 `start`） */
  startKey?: string
  /** 日期范围结束字段名（`type='date'` 时可选，默认 `end`） */
  endKey?: string
}

export interface ArtTableColumn {
  /** 字段名 */
  prop: string
  /** 列标题 */
  label: string
  width?: number | string
  minWidth?: number | string
  fixed?: boolean | 'left' | 'right'
  align?: 'left' | 'center' | 'right'
  /**
   * 列头排序。
   *
   * - `true`：el-table **本地**排序（仅当前页，分页列表不要用）；
   * - `'custom'`：**服务端**排序 —— 接口负责排序。页面用 `useTable` 时 ArtTable 会自动接管：
   *   点表头 → 写排序状态 → 回第 1 页 → 重新请求，页面零接线；
   *   未用 `useTable` 的页面仍需自己监听 `sort-change`。
   *   同时它也是「该列具备排序能力」的声明：列设置里只有这类列能勾选「排序」，
   *   计算列（分类名、统计数等）不要声明。
   */
  sortable?: boolean | 'custom'
  /** 具名插槽名：<template #xxx="{ row, value }">，不写则直接显示字段值 */
  slot?: string
  /**
   * 单元格格式化（与 el-table 的 formatter 同签名）。
   *
   * 展示口径（两位小数、百分号、千分位…）放在列定义里，比给每列写一个插槽省事，
   * 也不会出现「这一列格式化了、那一列忘了」。
   */
  formatter?: (row: Record<string, any>, column: unknown, cellValue: unknown, index: number) => string
  /** 锁定可见（如操作列、选择列） */
  lockVisible?: boolean
  /**
   * 默认是否显示。`false` = 初始隐藏（仍可在「列设置」里打开）。
   *
   * 与组件级 `defaultHidden` 等价，写在列上更直观；两者可混用（取并集）。
   * 仅在用户**从未调整过**本页列显隐时生效，之后以本地记录为准。
   */
  defaultShow?: boolean
  /**
   * 默认开启隐私遮罩：单元格先显示小眼睛，鼠标悬停才显示内容。
   *
   * 只是**初始值** —— 用户在列设置里动过隐私开关后，本地记录优先。
   * `lockVisible` 列（操作列等）不参与，避免把按钮藏起来。
   */
  defaultPrivacy?: boolean
  showOverflowTooltip?: boolean
  /** 列头筛选配置（配置后该列表头出现筛选入口） */
  filter?: ArtTableColumnFilter
  /**
   * 自绘列头的具名插槽：`<template #xxx="{ column, label }">`。
   *
   * 用于「把筛选条件放进列头」这类表头不是纯文字的列（如单元名称列头挂状态筛选）。
   * 与 `filter` 互斥：同时声明时以插槽为准（筛选 UI 由页面自己渲染）。
   * 插槽内容在页面作用域渲染，样式写在页面里即可。
   */
  headerSlot?: string
}
