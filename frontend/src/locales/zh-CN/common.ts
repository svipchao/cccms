/**
 * 通用按钮与全局提示。
 *
 * 与 `en-US/common.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 */
export default {
  confirm: '确定',
  cancel: '取消',
  save: '保存',
  delete: '删除',
  create: '新增',
  edit: '编辑',
  search: '查询',
  reset: '重置',
  export: '导出',
  refresh: '刷新',
  back: '返回',
  close: '关闭',
  tip: '提示',
  success: '操作成功',
  requestFailed: '请求失败',
  requestError: '请求错误 ({status})',
  networkError: '网络异常，请检查服务是否启动',
  loginExpired: '登录已失效，请重新登录',
  // 报错文案后缀：带上操作 ID，用户可直接到「日志 → 操作ID」检索
  actionIdSuffix: '（操作 {id}）',
  // 节点选择器（ArtNodePicker）内置文案，调用方无需感知
  nodePicker: {
    clear: '清空',
    expandAll: '全部展开',
    collapseAll: '全部折叠',
    selectAll: '全选',
    searchPlaceholder: '输入关键词搜索',
    searching: '搜索中…',
    noMatchUser: '没有匹配的用户',
  },
}
