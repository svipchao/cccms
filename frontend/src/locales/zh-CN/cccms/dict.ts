/**
 * 字典管理页（字典分类 / 字典类型 / 字典数据）。
 *
 * 与 `en-US/dict.ts` 保持**完全一致的 key 集合**。
 * 分类名、字典名、字典项显示名与值均属用户录入数据，不在此维护。
 */
export default {
  /* ---- 左侧分类树 ---- */
  categoryTreeTitle: '字典分类',
  addTopCategory: '新增顶级分类',
  addSubCategory: '新增子分类',
  renameCategory: '重命名 / 调整',
  all: '全部',
  uncategorized: '未分类',
  topCategory: '顶级分类',

  /* ---- 字典类型 ---- */
  name: '字典名称',
  typeName: '字典类型',
  category: '所属分类',
  remark: '备注',
  data: '字典数据',
  inputPlaceholder: '请输入',
  createType: '新增字典类型',
  editType: '编辑字典类型',
  namePlaceholder: '如 用户性别',
  typePlaceholder: '如 user_gender',
  remarkPlaceholder: '请输入备注',
  nameRequired: '请输入字典名称',
  typeRequired: '请输入字典类型',
  typeDeleteConfirm: '删除类型会同时删除其数据，确定？',

  /* ---- 分类表单 ---- */
  createCategory: '新增分类',
  editCategory: '编辑分类',
  parentCategory: '上级分类',
  categoryName: '分类名称',
  categoryNamePlaceholder: '如 系统字典',
  categoryNameRequired: '请输入分类名称',
  deleteCategoryTitle: '删除分类',
  categoryDeleteConfirm: '确定删除分类「{name}」？',

  /* ---- 批量操作 ---- */
  batchAction: '批量操作',
  batchActionCount: '批量操作（{count}）',
  batchEnable: '批量启用',
  batchDisable: '批量禁用',
  batchDelete: '批量删除',
  batchEnableDone: '已启用 {count} 条',
  batchEnableSkipped: '已启用 {affected} 条，{skipped} 条被跳过（越权或不存在）',
  batchDisableDone: '已禁用 {count} 条',
  batchDisableSkipped: '已禁用 {affected} 条，{skipped} 条被跳过（越权或不存在）',
  batchDeleteDone: '已删除 {count} 条',
  batchDeleteSkipped: '已删除 {affected} 条，{skipped} 条被跳过（越权或不存在）',
  batchTypeDeleteConfirm: '删除类型会同时删除其数据，确定删除所选 {count} 个类型？',
  batchDataDeleteConfirm: '确定删除所选 {count} 条字典数据？',

  /* ---- 字典数据 ---- */
  dataTitle: '字典数据 - {name}',
  createData: '新增数据',
  editData: '编辑数据',
  label: '显示名',
  value: '值',
  sort: '排序',
  status: '状态',
  enable: '启用',
  disable: '禁用',
  labelRequired: '请输入显示名',
  valueRequired: '请输入值',
  dataDeleteConfirm: '确定删除？',
  onlyView: '仅看：{name}',

  /* ---- 通用提示 ---- */
  saveSuccess: '保存成功',
  deleteSuccess: '删除成功',
}
