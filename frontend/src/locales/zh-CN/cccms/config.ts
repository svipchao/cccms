/**
 * 系统配置页。
 *
 * 与 `en-US/config.ts` 保持**完全一致的 key 集合**。
 * 配置项标题（`title`）、备注（`remark`）、分组名（`group_label`）与配置值均由后端按请求语言翻译下发，不在此维护。
 */
export default {
  pageTitle: '系统配置',
  totalCount: '共 {count} 项',
  dirtyCount: '{count} 项未保存',
  searchPlaceholder: '搜索配置项 / 键名',
  discard: '放弃修改',
  saveCount: '保存（{count}）',
  all: '全部',
  ungrouped: '未分组',
  noMatch: '没有匹配的配置项',
  empty: '暂无配置项',
  selectPlaceholder: '请选择',
  inputPlaceholder: '请输入',
  wmVarHint: '常用变量（点击插入）：',
  passwordPlaceholder: '留空表示不修改（敏感项加密存储）',
  discarded: '已放弃未保存的修改',
  savedCount: '已保存 {count} 项',
  copied: '已复制：{name}',
  copyFailed: '复制失败，请手动选择',
  leaveTitle: '未保存的修改',
  leaveConfirm: '有未保存的修改，确定离开吗？',
  leaveConfirmButton: '离开',
  stayButton: '留在本页',
}
