/**
 * 内置菜单标题（按后端菜单的 `path` 查表）。
 *
 * 菜单标题在数据库里是中文原文（`seeds.py` 内置菜单与管理员自建菜单共用 `title` 列），
 * 后端不会按请求语言翻译，所以由前端按 `path` 查表；未命中的 path 原样展示后端标题。
 *
 * 与 `en-US/menuTitle.ts` 保持完全一致的 key 集合（`npm run i18n:check` 会校验）。
 */
export default {
  '/dashboard': '控制台',
  '/permission': '权限管理',
  '/tenant': '租户管理',
  '/user': '用户管理',
  '/role': '角色管理',
  '/dept': '部门管理',
  '/post': '岗位管理',
  '/data_rule': '数据权限',
  '/menu': '菜单管理',
  '/system': '系统设置',
  '/notice': '通知公告',
  '/file': '附件管理',
  '/dict': '字典管理',
  '/config': '配置管理',
  '/online': '在线用户',
  '/crontab': '定时任务',
  '/generator': '代码生成',
  '/upgrade': '自动升级',
  '/log': '操作日志',
}
