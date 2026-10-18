/**
 * 操作日志页。
 *
 * 与 `en-US/log.ts` 保持**完全一致的 key 集合**。
 * 日志正文（title / content / node / message）与操作人昵称属于后端数据，不在此维护。
 */
export default {
  result: '结果',
  success: '成功',
  failed: '失败',
  username: '操作人',
  actionName: '操作名',
  path: '请求路径',
  requestId: '请求ID',
  actionId: '操作ID',
  sessionId: '会话ID',
  method: '方法',
  statusCode: '状态码',
  cost: '耗时',
  costMs: '耗时(ms)',
  time: '时间',
  deleteSelected: '删除选中',
  deleteSelectedCount: '删除选中（{count}）',
  deleteSelectedConfirm: '确定删除选中的 {count} 条日志？',
  batchDeleteTitle: '批量删除',
  deleteOneConfirm: '确定删除这条日志？',
  deleteSuccess: '删除成功',
  detail: '详情',
  detailTitle: '日志详情',
  node: '权限节点',
  request: '请求',
  message: '说明',
  params: '请求参数',
  response: '返回结果',

  /* ---- 链路视图（一次操作） ---- */
  viewAction: '查看本次操作',
  actionTitle: '操作 {id}',
  actionEmpty: '本次操作暂无可见记录',
  actionEmptyHint: '读请求默认不入日志，可在系统配置中开启 log.record_read 记录完整链路。',
  actionCopy: '复制操作ID',
  actionCopied: '操作ID已复制',
  actionCopyFailed: '复制失败，请手动选择操作ID',

  /* ---- 会话轨迹（审计视角，刷新即换） ---- */
  viewSession: '查看会话轨迹',
  sessionTitle: '会话轨迹 {id}',
  sessionEmpty: '该会话暂无可见记录',
  sessionActions: '操作数',
  sessionLoaded: '已加载',
  sessionLoadMore: '加载更多',
  sessionNoMore: '已加载全部',
  sessionRequestCount: '{count} 条请求',
  sessionActionFailed: '{count} 条失败',
  sessionUnknownAction: '未标记操作',

  /* ---- 两者共用 ---- */
  recordCount: '记录数',
  failedCount: '失败数',
  totalCost: '总耗时',
  chainTruncated: '记录较多，仅显示前 {limit} 条',
  chainScopeHint: '仅显示你有权查看的记录（受数据权限约束）。',
  chainFailed: '链路加载失败，请重试',
  chainNoAuth: '没有查看链路的权限',

  /* ---- 登录安全分析 ---- */
  tabOperation: '操作日志',
  tabLogin: '登录分析',
  analysisRange7: '最近 7 天',
  analysisRange30: '最近 30 天',
  analysisRange90: '最近 90 天',
  analysisRefresh: '刷新',
  analysisTotal: '登录次数',
  analysisFailed: '失败次数',
  analysisSuccess: '成功次数',
  analysisFailRate: '失败率',
  analysisFailUsers: '失败账号数',
  analysisFailIps: '失败 IP 数',
  analysisTrend: '登录趋势',
  analysisTrendSuccess: '成功',
  analysisTrendFailed: '失败',
  analysisTopUsers: '失败最多的账号',
  analysisTopIps: '失败最多的 IP',
  analysisIpChanges: '异地登录（同一账号成功登录的 IP 发生变化）',
  analysisFromIp: '上次 IP',
  analysisToIp: '本次 IP',
  analysisTime: '时间',
  analysisEmpty: '该范围内没有登录记录',
  analysisNoChange: '未发现异地登录',
  analysisHint: '数据受数据权限约束：仅统计你有权查看的登录记录。',

  /* ---- 导出（全局导出任务中心） ---- */
  exportQueued: '已加入导出队列（共 {total} 行），可到右上角「导出任务」查看进度',
}
