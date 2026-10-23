/**
 * 数据库备份 / 还原（F3）。
 *
 * 与 `en-US/cccms/backup.ts` 保持完全一致的 key 集合。
 * 文件名、错误原因、库名等均为后端下发数据，不在此维护。
 */
export default {
  /* ---- 工具栏 ---- */
  create: '立即备份',
  creating: '备份中，请勿关闭页面…',
  createTip: '备份同步执行：完成后列表里直接出现归档文件',
  deleteSelected: '删除所选',
  deleteNone: '请先勾选要删除的备份',
  engineTip: '优先 mysqldump（一致性快照）；未安装时降级 PDO 逐表导出，请留意引擎列',

  /* ---- 表格 ---- */
  fileLabel: '归档文件',
  kindLabel: '类型',
  engineLabel: '引擎',
  sizeLabel: '大小',
  tableCount: '表数',
  durationLabel: '耗时',
  statusLabel: '状态',
  createdAt: '创建时间',
  lastError: '失败原因',

  /* ---- 类型 ---- */
  kindManual: '手工',
  kindCron: '定时',
  kindPreRestore: '还原前自动',

  /* ---- 状态 ---- */
  statusRunning: '进行中',
  statusDone: '完成',
  statusFailed: '失败',

  /* ---- 引擎 ---- */
  engineMysqldump: 'mysqldump',
  enginePdo: 'PDO',
  enginePdoTip: 'PDO 降级路径没有跨表一致性快照：写入高峰期的备份可能自相矛盾',
  engineUnknown: '—',

  /* ---- 行内操作 ---- */
  download: '下载',
  downloadTip: '下载 .sql.gz（sha256 校验通过才会开始传输）',
  restore: '还原',
  deleteConfirm: '删除所选备份？归档文件会一并删除，且不可恢复。',
  deleteSuccess: '已删除 {count} 份备份',
  createSuccess: '备份完成：{name}（{size}）',

  /* ---- 还原 ---- */
  restoreTitle: '从备份还原',
  restoreWarning:
    '还原会覆盖当前库的表结构与数据，且 MySQL 的 DDL 无法回滚。系统会先自动备份当前库，确认失败原因与影响范围后再继续。',
  restoreTables: '要还原的表',
  restoreTablesTip: '留空 = 全量还原（覆盖整个库）；只勾选几张表时，其它表不受影响',
  restoreAllTables: '全量还原（不选则还原整库）',
  restoreConfirm: '输入数据库名确认',
  restoreConfirmTip: '请输入 {database} 以确认这次还原',
  restoreSubmit: '确认还原',
  restoreDone: '还原完成：{tables} 张表 / {statements} 条语句（还原前已自动备份 #{id}）',
  tablesLoading: '正在读取归档内容…',
  tablesEmpty: '归档里没有可还原的表',

  /* ---- 空态 ---- */
  empty: '暂无备份',
}
