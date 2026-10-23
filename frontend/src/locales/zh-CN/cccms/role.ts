/**
 * 角色管理页。
 *
 * 与 `en-US/role.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 */
export default {
  // 查询 / 左侧树 / 表格
  pleaseInput: '请输入',
  treeTitle: '角色层级',
  allRoles: '全部角色',
  onlyView: '仅看：{name} 及其下级',
  name: '角色名称',
  code: '角色标识',
  parent: '父角色',
  topRole: '顶级角色',
  dataScope: '数据范围',
  status: '状态',
  enabled: '启用',
  disabled: '禁用',

  // 行内操作
  copy: '复制',
  confirmDelete: '确定删除该角色？',

  // 新增 / 编辑表单
  createRole: '新增角色',
  editRole: '编辑角色',
  nameRequired: '请输入角色名称',
  codePlaceholder: '如 sale_manager（超管角色不可改）',
  codeRequired: '请输入角色标识',
  noneTop: '无（顶级）',
  parentTip: '子角色自动继承父角色的权限节点，此处只需勾选本角色独有节点。',
  scopeAll: '全部数据',
  scopeDeptAndBelow: '本部门及以下',
  scopeDept: '本部门',
  scopeSelf: '仅本人',
  scopeCustom: '自定义规则',
  scopeCustomShort: '自定义',
  scopeTipPrefix: '这里是「基线」。数据权限页里绑定到本角色的行级规则会',
  scopeTipAnd: '叠加（AND）',
  scopeTipMiddle: '在基线之上：选「本部门及以下」再配自定义规则 = 只看本部门及以下',
  scopeTipAnd2: '且',
  scopeTipSuffix:
    '满足规则的数据。「全部数据」档下自定义行级规则不生效；「自定义规则」档没有基线，一条规则都没命中就看不到任何数据。',
  permNodes: '权限节点',
  collapseAll: '全部折叠',
  expandAll: '全部展开',
  selectAll: '全选',
  clear: '清空',

  // 复制角色
  copyRole: '复制角色',
  sourceRole: '源角色',
  newRoleName: '新角色名称',
  newRoleNameRequired: '请输入新角色名称',
  newRoleCode: '新角色标识',
  newRoleCodePlaceholder: '如 sale_manager_copy',
  newRoleCodeRequired: '请输入新角色标识',
  copyParentTip: '默认与源角色一致；继承深度不得超过 5 层。',
  saveAsTemplate: '另存为模板',
  templateTip: '开启后副本为「禁用」状态，不影响任何人的权限',
  copyNameSuffix: '{name} 副本',

  // 提示
  entityLabel: '角色',
  saveSuccess: '保存成功',
  deleteSuccess: '删除成功',
  copySuccess: '复制成功',
  copyTemplateSuccess: '已另存为模板（禁用状态）',
}
