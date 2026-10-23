/**
 * 诸葛量免登录盯盘面板（/#/panel，浏览器扩展 iframe 内嵌）。
 * key 集合必须与 en-US/panel.ts 完全一致（npm run i18n:check 硬校验）。
 */
export default {
  title: '诸葛量盯盘助手',
  // 视图切换
  viewNotify: '通知',
  viewMonitor: '监控',
  viewAll: '全部',
  // 时间窗
  time10: '10分',
  time30: '30分',
  time60: '1时',
  time180: '3时',
  time360: '6时',
  refresh: '刷新',
  updatedAt: '更新于 {time}',
  // 指标卡
  cardCost: '时段-消耗',
  cardTrade: '时段-加粉',
  cardCostPerTrade: '时段-成本',
  cardChat: '时段-开口率',
  cardDelete: '时段-删除率',
  cardAddFansEnd: '加粉结束',
  addFansSub: '共 {total} | 余 {remaining}',
  // 通知级别
  levelCritical: '严重',
  levelWarning: '提醒',
  levelInfo: '通知',
  notifyReady: '就绪',
  notifyWatching: '自动监测中...',
  // 状态文案
  loading: '加载中...',
  emptyData: '数据为空',
  loadFailed: '加载失败',
  retry: '重试',
  noUserinfo: '缺少授权信息，请从盯盘助手悬浮面板入口打开本页。',
}
