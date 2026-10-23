/**
 * Task center · job queue (F5).
 *
 * Key set must stay **exactly identical** to `zh-CN/cccms/job.ts`.
 */
export default {
  /* ---- tabs ---- */
  tabTasks: 'Cron jobs',
  tabJobs: 'Job queue',
  tabTip:
    'The job consumer only runs on Linux/macOS; when the queue is unavailable, exports fall back to "generate on download"',

  /* ---- stat cards ---- */
  statsPending: 'Pending',
  statsRunning: 'Running',
  statsRetry: 'Retrying',
  statsDead: 'Dead',
  statsDone: 'Succeeded',
  statsTotal: 'Total',
  heartbeat: 'Consumer heartbeat',
  heartbeatNever: 'No heartbeat',
  heartbeatTip:
    'The consumer writes a heartbeat on every tick; "No heartbeat" means the process is not running (Windows or not started)',
  heartbeatAgo: '{n}s ago',

  /* ---- query / table ---- */
  queueLabel: 'Queue',
  queueAll: 'All queues',
  targetLabel: 'Target',
  targetFilter: 'Class name',
  statusLabel: 'Status',
  attempts: 'Attempts',
  availableAt: 'Available at',
  createdAt: 'Created at',
  lastError: 'Last error',
  payload: 'Payload',
  refresh: 'Refresh',

  /* ---- statuses ---- */
  statusPending: 'Pending',
  statusRunning: 'Running',
  statusDone: 'Succeeded',
  statusRetry: 'Failed, retrying',
  statusDead: 'Dead',

  /* ---- row actions ---- */
  logs: 'Logs',
  retry: 'Retry',
  retryConfirm: 'Reset to pending and clear attempt counter. Retry this job?',
  retryTip: 'Only applies to jobs that have not succeeded',
  deleteConfirm: 'Delete the selected jobs? Their execution logs are removed as well.',
  deleteSelected: 'Delete selected',
  deleteNone: 'Select the jobs to delete first',
  deleteOnlyFinished: 'Only succeeded or dead jobs can be deleted',
  deleteSuccess: 'Deleted {count} job(s)',

  /* ---- execution log drawer ---- */
  logsTitle: 'Execution logs - #{id}',
  logAttempt: 'Attempt {n}',
  logAttemptLabel: 'Attempt',
  logResult: 'Result',
  logSuccess: 'Success',
  logFailed: 'Failed',
  logCost: 'Cost (ms)',
  logOutput: 'Output',
  logTime: 'Time',
  logEmpty: 'This job has no execution records yet',
  detailTarget: 'Target',
  detailQueue: 'Queue',
  detailUniqueKey: 'Idempotency key',
  detailAvailable: 'Available at',
  detailReserved: 'Reserved at',
  detailFinished: 'Finished at',
  detailAttempts: 'Attempts',
  detailCreated: 'Created at',
  detailUpdated: 'Updated at',
  detailLastError: 'Last error',
  detailPayload: 'Payload',
  detailNone: '—',

  /* ---- empty ---- */
  empty: 'No jobs',
}
