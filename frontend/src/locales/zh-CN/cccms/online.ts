/**
 * 在线用户（会话）管理页。
 *
 * 与 `en-US/online.ts` 保持**完全一致的 key 集合**（`npm run i18n:check` 会校验）。
 * 用户名 / 昵称 / IP / 设备等信息来自用户数据或 UA 解析结果，均不翻译。
 */
export default {
  keywordLabel: '关键词',
  keywordPlaceholder: '账号 / 昵称 / IP / 设备 / 系统 / 浏览器',
  alert: '会话来自 Redis 索引；「强制下线」会同时作废已发出的令牌，对方下一次请求即失效。',
  user: '用户',
  device: '设备',
  os: '系统',
  browser: '浏览器',
  loginAt: '登录时间',
  lastActive: '最后活跃',
  expireAt: '会话过期',
  kick: '强制下线',
  kickUser: '该用户全部下线',
  kickConfirm: '确定让「{name}」的该会话立即下线？',
  kickUserTitle: '强制用户下线',
  kickUserConfirm: '将让「{name}」的所有登录会话全部失效，确定继续？',
  kickSuccess: '已强制下线',
  kickUserSuccess: '已强制下线 {count} 个会话',
}
