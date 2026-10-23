/**
 * 用户管理页。
 *
 * 与 `en-US/user.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 */
export default {
  // 查询 / 表格
  username: '用户名',
  pleaseInput: '请输入',
  nickname: '昵称',
  status: '状态',
  all: '全部',
  enabled: '启用',
  disabled: '禁用',
  lastLogin: '最后登录',

  // 批量操作
  batchAction: '批量操作',
  batchActionCount: '批量操作（{count}）',
  batchEnable: '批量启用',
  batchDisable: '批量禁用',
  batchAssign: '批量分配角色 / 部门 / 岗位',
  batchDelete: '批量删除',
  batchToggle: '批量启停',
  batchVerbDelete: '删除',
  batchVerbUpdate: '更新',
  batchVerbAssign: '分配',
  batchDone: '已{action} {affected} 条',
  batchSkipped: '已{action} {affected} 条，{skipped} 条被跳过（越权、自己、超管或不存在）',
  confirmBatchDelete: '确定删除所选 {count} 个用户？删除后可在回收站恢复。',
  confirmBatchEnable: '确定启用所选 {count} 个用户？',
  confirmBatchDisable: '确定禁用所选 {count} 个用户？禁用后对方下一次请求即失效。',
  assignTip: '将替换所选 {count} 个用户的对应字段（保持原样则留空）。',
  keepAsIs: '保持原样',
  assignAtLeastOne: '请至少选择一项要分配的角色 / 部门 / 岗位',

  // 行内操作与回收站
  entityLabel: '用户',
  resetPassword: '重置密码',
  confirmDelete: '确定删除该用户？',

  // 新增 / 编辑表单
  createUser: '新增用户',
  editUser: '编辑用户',
  password: '密码',
  newPassword: '新密码',
  passwordPlaceholder: '至少 6 位，含大小写/数字/符号中的 2 类',
  usernameRequired: '请输入用户名',
  nicknamePlaceholder: '请输入昵称',
  phone: '手机号',
  phonePlaceholder: '请输入手机号',
  email: '邮箱',
  emailPlaceholder: '请输入邮箱',
  role: '角色',
  rolePlaceholder: '请选择角色',
  dept: '部门',
  deptPlaceholder: '请选择部门',
  post: '岗位',
  postPlaceholder: '请选择岗位',
  passwordRequired: '请输入密码',
  newPasswordRequired: '请输入新密码',

  // 提示
  saveSuccess: '保存成功',
  deleteSuccess: '删除成功',
  resetSuccess: '重置成功',
}
