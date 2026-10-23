/**
 * 上游升级页。
 *
 * 与 `en-US/upgrade.ts` 保持**完全一致的 key 集合**。
 * 版本号、提交信息、文件路径等均为后端/Git 下发数据，不在此维护。
 */
export default {
  /* ---- 环境拦截（仅本地开发环境可用）---- */
  blockedTitle: '仅限本地开发环境，已禁用在线升级',
  blockedDesc:
    '{reason}。线上是「已构建」形态：前端产物在 public 下、docs 等目录并不存在，与仓库的目录结构并不一致 —— 此时按仓库相对路径覆盖，会写出线上本不该有的文件、也覆盖不到线上真正要用的那份代码，轻则代码被改坏，重则系统不可用，因此这里连同升级操作一起拦截。线上请走发布流程更新代码；本地开发环境需同时满足「打开 upgrade.enable」与「仓库根存在 frontend/package.json」。',
  switchOffTitle: '上游同步已关闭',
  switchOffDesc:
    '{reason}。这是总开关，默认关闭：本地开发环境需要它时，在 server/.env 里加上 CCCMS_UPGRADE_ENABLE=true（或把 plugin/cccms/config/upgrade.php 的 enable 改为 true），然后 reload / 重启后端进程即可。',
  noGitTitle: '未检测到 git',
  noGitDesc: '服务器上找不到 git 可执行文件，请在 plugin/cccms/config/upgrade.php 的 git 项里填写绝对路径。',

  /* ---- 版本概览 ---- */
  current: '当前',
  target: '目标',
  noBaseline: '未建立基线',
  upstreamFiles: '上游 {count} 个文件',
  upToDate: '已是最新',
  commitCount: '{count} 个提交',
  notChecked: '尚未检查',
  statSafe: '可安全覆盖',
  statNew: '上游新增',
  statPending: '冲突待合并',
  statLines: '上游代码改动',

  /* ---- 未建立基线 ---- */
  emptyBaseline: '尚未建立基线，无法判断本地相对上游改过哪些文件',
  initNow: '立即建立基线',
  initTip:
    '基线会记录「当前代码基于的上游版本」各文件的内容指纹，之后升级时据此判断哪些文件是本地改过的（绝不能覆盖），哪些可以直接更新。',

  /* ---- 操作栏 ---- */
  sourceLabel: '同步源',
  targetRefLabel: '目标版本',
  trackBranch: '跟踪分支 {name}',
  check: '检查更新',
  run: '立即升级',
  forceLabel: '同时覆盖 {count} 个冲突文件（本地也改过，覆盖前会自动备份）',
  forceHint: '不勾选时这些文件会被跳过，保持本地版本',
  forceAllLabel: '强制对齐上游（覆盖 {local} 个本地定制、恢复 {deleted} 个本地删除、删除 {removed} 个上游已移除）',
  forceAllHint: '适合「下游落后太多、整体对齐上游」；所有改动都会先备份',
  lastRun: '上次升级：写入 {written}，删除 {removed}，备份 {backed}',
  backupDir: '备份目录：{path}',
  skipped: '跳过：{count} 个冲突文件',
  maintenanceError: '后置同步失败：{error}',
  needReload: '代码已更新，请重启服务（Linux：php start.php reload / Windows：重启 php windows.php）后生效',

  /* ---- 变更明细 ---- */
  filesTab: '变更文件（{count}）',
  commitsTab: '提交记录（{count}）',
  filterChanged: '会被改动',
  filterAll: '全部',
  filterKept: '仅本地保留',
  localOnlyTip: '本地独有文件 {count} 个（业务插件，不会被改动）',
  noFiles: '没有需要展示的文件',
  noCommits: '没有新的提交记录',
  colFile: '文件',
  colKind: '分类',
  colAdded: '增加',
  colDeleted: '删除',

  /* ---- 确认与提示 ---- */
  defaultVersion: '默认版本',
  initTitle: '建立基线',
  initConfirm: '将以「{source}」的 {base} 建立基线，用于识别本地改动。',
  initSuccess: '基线已建立（{ref}）：本地已改 {modified} 个，本地新增 {localOnly} 个',
  initSuccessFallback:
    '基线已建立（{ref}，因 {fallback} 不存在而回退）：本地已改 {modified} 个，本地新增 {localOnly} 个',
  runTitle: '确认升级',
  runConfirmRef: '目标版本：{ref}（{commit}）',
  runConfirmWrite: '将写入 / 覆盖 {count} 个文件',
  runConfirmWriteRemove: '将写入 / 覆盖 {write} 个文件，删除 {remove} 个',
  runConfirmConflict: '其中 {count} 个是本地也改过的冲突文件',
  runConfirmForceAll: '已勾选「强制对齐上游」：本地定制与本地删除的文件也会被覆盖 / 恢复。',
  runConfirmBackup: '覆盖前会自动备份，本地独有文件不受影响。',
  runSuccess: '升级完成',
}
