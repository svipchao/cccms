import { http } from './request'

export type RefreshScope = 'all' | 'menu' | 'perm' | 'cache'

export interface RefreshResult {
  /** 菜单同步结果（读 db/menu.php） */
  menu?: { created: number; updated: number; removed: number }
  /** 按钮节点同步结果（扫描控制器 #[Permission] 注解） */
  perm?: { created: number; skipped: number }
  /** 缓存清理结果 */
  cache?: Record<string, boolean>
  /** 各步骤耗时（毫秒），用于定位刷新变慢的环节 */
  cost?: Record<string, number>
}

/**
 * 系统同步 / 清理缓存。
 *
 * 与命令行 `cccms:menu-sync` / `cccms:perm-scan` 共用同一份实现，
 * 只是把入口搬到后台界面，不用再登服务器手动执行。
 * 维护操作可能扫描全部菜单与控制器注解，单独放宽超时（默认 15s 对老库不够）。
 */
export function systemRefresh(scope: RefreshScope) {
  return http.post<RefreshResult>('/system/refresh', { scope }, { timeout: 60000 })
}
