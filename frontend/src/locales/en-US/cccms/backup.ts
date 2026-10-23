/**
 * Database backup / restore (F3).
 *
 * Key set must stay **exactly identical** to `zh-CN/cccms/backup.ts`.
 */
export default {
  /* ---- toolbar ---- */
  create: 'Back up now',
  creating: 'Backing up, keep this page open…',
  createTip: 'Backups run synchronously: the archive appears in the list when done',
  deleteSelected: 'Delete selected',
  deleteNone: 'Select the backups to delete first',
  engineTip:
    'mysqldump (consistent snapshot) is preferred; falls back to per-table PDO export — check the engine column',

  /* ---- table ---- */
  fileLabel: 'Archive',
  kindLabel: 'Type',
  engineLabel: 'Engine',
  sizeLabel: 'Size',
  tableCount: 'Tables',
  durationLabel: 'Duration',
  statusLabel: 'Status',
  createdAt: 'Created at',
  lastError: 'Error',

  /* ---- kinds ---- */
  kindManual: 'Manual',
  kindCron: 'Scheduled',
  kindPreRestore: 'Pre-restore',

  /* ---- statuses ---- */
  statusRunning: 'Running',
  statusDone: 'Done',
  statusFailed: 'Failed',

  /* ---- engines ---- */
  engineMysqldump: 'mysqldump',
  enginePdo: 'PDO',
  enginePdoTip:
    'The PDO fallback has no cross-table consistent snapshot: backups taken under write load may be inconsistent',
  engineUnknown: '—',

  /* ---- row actions ---- */
  download: 'Download',
  downloadTip: 'Download .sql.gz (transfer starts only after the sha256 check passes)',
  restore: 'Restore',
  deleteConfirm: 'Delete the selected backups? The archive files are removed as well and cannot be recovered.',
  deleteSuccess: 'Deleted {count} backup(s)',
  createSuccess: 'Backup completed: {name} ({size})',

  /* ---- restore ---- */
  restoreTitle: 'Restore from backup',
  restoreWarning:
    'Restoring overwrites current tables and data, and MySQL DDL cannot be rolled back. A pre-restore backup is created automatically — review the failure reason and impact first.',
  restoreTables: 'Tables to restore',
  restoreTablesTip:
    'Leave empty for a full restore (whole database); selecting a few tables leaves the others untouched',
  restoreAllTables: 'Full restore (whole database when empty)',
  restoreConfirm: 'Type the database name to confirm',
  restoreConfirmTip: 'Type {database} to confirm this restore',
  restoreSubmit: 'Restore now',
  restoreDone: 'Restore finished: {tables} table(s) / {statements} statement(s) (pre-restore backup #{id})',
  tablesLoading: 'Reading archive…',
  tablesEmpty: 'The archive contains no restorable tables',

  /* ---- empty ---- */
  empty: 'No backups',
}
