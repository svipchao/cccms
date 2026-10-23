/**
 * 租户管理页 + 顶栏租户切换器。
 *
 * 与 `en-US/tenant.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 * 租户名称 / 标识 / 联系人由用户录入，不在语言包内。
 */
export default {
  // ---- 列表 ----
  searchPlaceholder: '请输入名称或标识',
  createTitle: '新增租户',
  editTitle: '编辑租户',
  nameLabel: '租户名称',
  namePlaceholder: '请输入租户名称',
  codeLabel: '租户标识',
  codePlaceholder: '如 acme，仅字母数字下划线短横线',
  domainLabel: '自定义域名',
  domainPlaceholder: '如 crm.example.com，留空表示不绑定',
  domainTip: '绑定后经该域名访问只能使用此租户，不能选择平台登录',
  domainInvalid: '域名格式不正确：只填主机名（如 crm.example.com），不要带协议或端口',
  contactLabel: '联系人',
  contactPlaceholder: '请输入联系人',
  phoneLabel: '联系电话',
  phonePlaceholder: '请输入联系电话',
  expireLabel: '到期时间',
  expirePlaceholder: '留空表示不过期',
  expireNever: '不过期',
  remarkLabel: '备注',
  remarkPlaceholder: '请输入备注',
  statusLabel: '状态',
  userCountLabel: '账号数',
  enabled: '启用',
  disabled: '禁用',
  platformTag: '平台',
  platformHint: '平台租户是系统内置的，不可编辑或删除',
  nameRequired: '请输入租户名称',
  codeRequired: '请输入租户标识',
  saveSuccess: '保存成功',
  deleteSuccess: '删除成功',
  deleteConfirm: '确定删除该租户？',
  deleteTitle: '删除租户',

  // ---- 顶栏切换器（超管专属） ----
  switchTenant: '切换租户',
  currentTenant: '当前租户',
  switchConfirm: '确定切换到「{name}」？切换后只能看到该租户的数据。',
  switchTitle: '切换租户',
  switchSuccess: '已切换到「{name}」',
  backToPlatform: '返回平台',
  loadFailed: '租户列表加载失败',
}
