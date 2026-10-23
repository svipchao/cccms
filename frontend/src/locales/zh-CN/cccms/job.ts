/**
 * 任务中心 · 作业队列（F5）。
 *
 * 与 `en-US/cccms/job.ts` 保持**完全一致的 key 集合**。
 * 作业参数、错误原因等均为后端下发数据，不在此维护。
 */
export default {
  /* ---- 标签页 ---- */
  tabTasks: '定时任务',
  tabJobs: '作业队列',
  tabTip: '作业进程仅跑在 Linux/macOS；队列不可用时导出会回落到「下载时补生成」',

  /* ---- 统计卡 ---- */
  statsPending: '待执行',
  statsRunning: '执行中',
  statsRetry: '待重试',
  statsDead: '死信',
  statsDone: '成功',
  statsTotal: '总数',
  heartbeat: '消费者心跳',
  heartbeatNever: '未心跳',
  heartbeatTip: '队列进程每轮 tick 写一次心跳；「未心跳」说明进程没启动（Windows 或未 start）',
  heartbeatAgo: '{n} 秒前',

  /* ---- 查询 / 表格 ---- */
  queueLabel: '队列',
  queueAll: '全部队列',
  targetLabel: '执行目标',
  targetFilter: '类名关键字',
  statusLabel: '状态',
  attempts: '尝试',
  availableAt: '最早执行',
  createdAt: '创建时间',
  lastError: '最近失败',
  payload: '参数',
  refresh: '刷新',

  /* ---- 状态 ---- */
  statusPending: '待执行',
  statusRunning: '执行中',
  statusDone: '成功',
  statusRetry: '失败待重试',
  statusDead: '死信',

  /* ---- 行内操作 ---- */
  logs: '日志',
  retry: '重试',
  retryConfirm: '重置为待执行并清空尝试次数，确定重试该作业？',
  retryTip: '仅对未成功的作业生效',
  deleteConfirm: '删除所选作业？其执行日志会一并删除。',
  deleteSelected: '删除所选',
  deleteNone: '请先勾选要删除的作业',
  deleteOnlyFinished: '只有「成功 / 死信」的作业可以删除',
  deleteSuccess: '已删除 {count} 条作业',

  /* ---- 执行日志抽屉 ---- */
  logsTitle: '执行日志 - #{id}',
  logAttempt: '第 {n} 次',
  logAttemptLabel: '尝试',
  logResult: '结果',
  logSuccess: '成功',
  logFailed: '失败',
  logCost: '耗时(ms)',
  logOutput: '输出',
  logTime: '时间',
  logEmpty: '该作业还没有执行记录',
  detailTarget: '执行目标',
  detailQueue: '队列',
  detailUniqueKey: '幂等键',
  detailAvailable: '最早执行',
  detailReserved: '认领时间',
  detailFinished: '结束时间',
  detailAttempts: '尝试次数',
  detailCreated: '创建时间',
  detailUpdated: '更新时间',
  detailLastError: '最近失败',
  detailPayload: '参数',
  detailNone: '—',

  /* ---- 空态 / 提示 ---- */
  empty: '暂无作业',
}
