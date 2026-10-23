/**
 * 岗位管理页。
 *
 * 与 `en-US/post.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 * 岗位编码 / 岗位名称由用户录入，不在语言包内。
 */
export default {
  nameLabel: '岗位名称',
  searchPlaceholder: '请输入',
  batchAction: '批量操作',
  batchActionWithCount: '批量操作（{count}）',
  batchEnable: '批量启用',
  batchDisable: '批量禁用',
  batchDelete: '批量删除',
  recycleLabel: '岗位',
  enabled: '启用',
  disabled: '禁用',
  deleteConfirm: '确定删除该岗位？',
  createTitle: '新增岗位',
  editTitle: '编辑岗位',
  codeLabel: '岗位编码',
  codePlaceholder: '如 sale_manager',
  codeRequired: '请输入岗位编码',
  namePlaceholder: '请输入岗位名称',
  nameRequired: '请输入岗位名称',
  sortLabel: '排序',
  statusLabel: '状态',
  saveSuccess: '保存成功',
  deleteSuccess: '删除成功',
  batchEnableSuccess: '已启用 {count} 条',
  batchEnablePartial: '已启用 {count} 条，{skipped} 条被跳过（越权、不存在或下挂用户）',
  batchDisableSuccess: '已禁用 {count} 条',
  batchDisablePartial: '已禁用 {count} 条，{skipped} 条被跳过（越权、不存在或下挂用户）',
  batchDeleteSuccess: '已删除 {count} 条',
  batchDeletePartial: '已删除 {count} 条，{skipped} 条被跳过（越权、不存在或下挂用户）',
  batchDeleteTitle: '批量删除',
  batchDeleteConfirm: '确定删除所选 {count} 个岗位？',
}
