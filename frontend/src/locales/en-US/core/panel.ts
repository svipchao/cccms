/**
 * Login-free ZhugeLiang monitor panel (/#/panel, embedded inside the extension iframe).
 * The key set must stay identical to zh-CN/panel.ts (enforced by npm run i18n:check).
 */
export default {
  title: 'ZGL Monitor',
  // View switcher
  viewNotify: 'Alerts',
  viewMonitor: 'Metrics',
  viewAll: 'All',
  // Time windows
  time10: '10m',
  time30: '30m',
  time60: '1h',
  time180: '3h',
  time360: '6h',
  refresh: 'Refresh',
  updatedAt: 'Updated {time}',
  // Metric cards
  cardCost: 'Period cost',
  cardTrade: 'Period new fans',
  cardCostPerTrade: 'Period cost/fan',
  cardChat: 'Period chat rate',
  cardDelete: 'Period delete rate',
  cardAddFansEnd: 'Fans goal ETA',
  addFansSub: 'Total {total} | Left {remaining}',
  // Alert levels
  levelCritical: 'Critical',
  levelWarning: 'Warning',
  levelInfo: 'Info',
  notifyReady: 'Ready',
  notifyWatching: 'Auto-monitoring...',
  // States
  loading: 'Loading...',
  emptyData: 'No data',
  loadFailed: 'Failed to load',
  retry: 'Retry',
  noUserinfo: 'Missing authorization info. Please open this page from the ZGL assistant floating panel.',
}
