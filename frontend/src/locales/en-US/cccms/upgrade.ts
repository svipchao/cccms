/**
 * Upstream upgrade page.
 *
 * The key set mirrors `zh-CN/upgrade.ts` exactly.
 * Version numbers, commit info and file paths are backend/Git-provided data and are
 * not kept here.
 */
export default {
  /* ---- Environment blocked (local development only) ---- */
  blockedTitle: 'Local development only — online upgrade is disabled',
  blockedDesc:
    '{reason}. Production is a built layout: frontend assets live under public/ and docs/ does not exist, so the tree does not match the repository — applying a repo-relative update would write files that should not be there and miss the files production actually needs, corrupting the code or taking the site down. Upgrading from here is therefore blocked. Use your release process for production; for local development, enable upgrade.enable and keep frontend/package.json in the project root.',
  switchOffTitle: 'Upstream sync is turned off',
  switchOffDesc:
    '{reason}. This is the master switch and it defaults to off. To use it in a local development environment, add CCCMS_UPGRADE_ENABLE=true to server/.env (or set enable to true in plugin/cccms/config/upgrade.php) and reload/restart the backend process.',
  noGitTitle: 'git not detected',
  noGitDesc:
    'The git executable was not found on the server; set the absolute path for the git item in plugin/cccms/config/upgrade.php.',

  /* ---- Version overview ---- */
  current: 'Current',
  target: 'Target',
  noBaseline: 'No baseline yet',
  upstreamFiles: '{count} upstream file(s)',
  upToDate: 'Up to date',
  commitCount: '{count} commit(s)',
  notChecked: 'Not checked yet',
  statSafe: 'Safe to overwrite',
  statNew: 'New upstream',
  statPending: 'Conflicts to merge',
  statLines: 'Upstream code changes',

  /* ---- No baseline ---- */
  emptyBaseline: 'No baseline yet; cannot tell which files were changed locally relative to upstream',
  initNow: 'Create baseline now',
  initTip:
    'The baseline records the content fingerprint of each file at the upstream version the current code is based on; upgrades then use it to tell which files were changed locally (must never be overwritten) and which can be updated directly.',

  /* ---- Toolbar ---- */
  sourceLabel: 'Sync source',
  targetRefLabel: 'Target version',
  trackBranch: 'Tracking branch {name}',
  check: 'Check for updates',
  run: 'Upgrade now',
  forceLabel: 'Also overwrite {count} conflicting file(s) (changed locally too; backed up before overwriting)',
  forceHint: 'When unchecked these files are skipped and the local version is kept',
  forceAllLabel:
    'Force-align to upstream (overwrite {local} local customization(s), restore {deleted} locally-deleted file(s), remove {removed} upstream-removed file(s))',
  forceAllHint: 'For "downstream is far behind and should match upstream"; every change is backed up first',
  lastRun: 'Last upgrade: {written} written, {removed} removed, {backed} backed up',
  backupDir: 'Backup directory: {path}',
  skipped: 'Skipped: {count} conflicting file(s)',
  maintenanceError: 'Post-sync failed: {error}',
  needReload:
    'Code updated; restart the service to take effect (Linux: php start.php reload / Windows: restart php windows.php)',

  /* ---- Change details ---- */
  filesTab: 'Changed files ({count})',
  commitsTab: 'Commits ({count})',
  filterChanged: 'Will be changed',
  filterAll: 'All',
  filterKept: 'Local only',
  localOnlyTip: '{count} local-only file(s) (business plugins, will not be changed)',
  noFiles: 'No files to display',
  noCommits: 'No new commits',
  colFile: 'File',
  colKind: 'Category',
  colAdded: 'Added',
  colDeleted: 'Deleted',

  /* ---- Confirmation and messages ---- */
  defaultVersion: 'default version',
  initTitle: 'Create baseline',
  initConfirm: 'A baseline will be created from {base} of "{source}" to identify local changes.',
  initSuccess: 'Baseline created ({ref}): {modified} changed locally, {localOnly} added locally',
  initSuccessFallback:
    'Baseline created ({ref}, fell back because {fallback} does not exist): {modified} changed locally, {localOnly} added locally',
  runTitle: 'Confirm upgrade',
  runConfirmRef: 'Target version: {ref} ({commit})',
  runConfirmWrite: '{count} file(s) will be written / overwritten',
  runConfirmWriteRemove: '{write} file(s) will be written / overwritten and {remove} removed',
  runConfirmConflict: '{count} of them are conflicting files that were also changed locally',
  runConfirmForceAll:
    '"Force-align" is checked: local customizations and locally-deleted files will also be overwritten / restored.',
  runConfirmBackup: 'Files are backed up automatically before overwriting; local-only files are unaffected.',
  runSuccess: 'Upgrade completed',
}
