/**
 * Built-in menu titles, looked up by the menu's `path`.
 *
 * The menu title column stores Chinese source text (seeds and admin-created menus share it),
 * so the frontend translates it by path; unknown paths fall back to the backend title.
 *
 * Key set mirrors `zh-CN/menuTitle.ts` exactly (`npm run i18n:check` verifies this).
 */
export default {
  '/dashboard': 'Console',
  '/permission': 'Access control',
  '/tenant': 'Tenants',
  '/user': 'Users',
  '/role': 'Roles',
  '/dept': 'Departments',
  '/post': 'Positions',
  '/data_rule': 'Data rules',
  '/menu': 'Menus',
  '/system': 'System',
  '/notice': 'Notices',
  '/file': 'Attachments',
  '/dict': 'Dictionaries',
  '/config': 'Config',
  '/online': 'Online users',
  '/crontab': 'Scheduled tasks',
  '/generator': 'Code generator',
  '/upgrade': 'Upgrade',
  '/log': 'Operation logs',
}
