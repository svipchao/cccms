/**
 * Online session management page.
 *
 * The key set mirrors `zh-CN/online.ts` exactly (`npm run i18n:check` verifies this).
 * Username / nickname / IP / device come from user data or UA parsing and are not translated.
 */
export default {
  keywordLabel: 'Keyword',
  keywordPlaceholder: 'Account / nickname / IP / device / OS / browser',
  alert:
    'Sessions come from the Redis index. "Force offline" also revokes issued tokens, so the peer\'s next request fails immediately.',
  user: 'User',
  device: 'Device',
  os: 'OS',
  browser: 'Browser',
  loginAt: 'Login time',
  lastActive: 'Last active',
  expireAt: 'Session expires',
  kick: 'Force offline',
  kickUser: 'Offline all sessions',
  kickConfirm: 'Force session of "{name}" offline now?',
  kickUserTitle: 'Force user offline',
  kickUserConfirm: 'All login sessions of "{name}" will be invalidated. Continue?',
  kickSuccess: 'Forced offline',
  kickUserSuccess: 'Forced {count} session(s) offline',
}
