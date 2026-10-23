<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;

/**
 * 用户认证与权限解析（角色继承：子角色继承父角色）。
 *
 * 权限集合的缓存与失效见 `PermissionCache`：任何 RBAC 变更都必须调用
 * `PermissionCache::bump()`，否则缓存要等 TTL（300s）才过期。
 */
final class AuthService
{
    /**
     * 根据用户 ID 构建上下文；用户不存在 / 被禁用 / 已进回收站 / 租户不可用返回 null。
     *
     * 用户行**每请求实时查库**（保证禁用 / 删除用户立即 401，这是硬约束）；
     * 角色与权限集合走 `PermissionCache`（Redis + 版本号失效），RBAC 变更时立即作废。
     *
     * 租户口径：
     *   - 账号归属租户 = `sys_user.tenant_id`（`homeTenantId`）；
     *   - **生效**租户 = 令牌里的 `tid` 声明（`$activeTenantId`），超管显式切换后与归属不同；
     *   - 非超管**不得跨租户**：令牌 `tid` 与账号归属不一致时返回 null（→ 401 重新登录），
     *     这条同时兜住了「账号被改到别的租户后旧令牌继续用」与「伪造 tid」两种情形；
     *   - 归属/生效租户被停用或过期同样返回 null，停用即时生效。
     *
     * @param int|null $activeTenantId 令牌里的 `tid`；null = 未携带（登录链路 / 旧令牌）→ 用归属租户
     */
    public static function buildContext(int $userId, ?int $activeTenantId = null): ?UserContext
    {
        // 软删除的用户立即失效：禁用/删除后旧令牌下一次请求就 401。
        // 只取构建上下文所需的列，不把 password 哈希读进内存（每请求一次）。
        $user = SoftDelete::apply(Db::name('user'))
            ->field(['id', 'status', 'tenant_id', 'username', 'nickname', 'avatar'])
            ->where('id', $userId)
            ->find();
        if (!$user || (int)$user['status'] !== 1) {
            return null;
        }

        $cached = PermissionCache::load($userId);
        if ($cached === null) {
            // 登录链路还没有 `$request->user`，归属租户只能取刚查出来的用户行
            $roleIds = self::effectiveRoleIds($userId, false, (int)($user['tenant_id'] ?? TenantContext::PLATFORM_ID));
            $cached  = [
                'roleIds'     => $roleIds,
                'roles'       => self::roleCodes($roleIds),
                'permissions' => self::permissions($roleIds),
                'superAdmin'  => self::hasSuperAdmin($roleIds),
            ];
            PermissionCache::store($userId, $cached);
        }

        $home      = (int)($user['tenant_id'] ?? TenantContext::PLATFORM_ID);
        $isSuper   = (bool)$cached['superAdmin'];

        if ($isSuper) {
            // 超管可以显式切换租户；未带 tid 时留在自己的归属租户
            $active = $activeTenantId ?? $home;
        } else {
            if ($activeTenantId !== null && $activeTenantId !== $home) {
                return null;
            }
            $active = $home;
        }

        if (!TenantContext::usable($active)) {
            return null;
        }

        return new UserContext(
            id:           (int)$user['id'],
            username:     (string)$user['username'],
            nickname:     (string)$user['nickname'],
            superAdmin:   $isSuper,
            roles:        (array)$cached['roles'],
            permissions:  (array)$cached['permissions'],
            avatar:       (string)($user['avatar'] ?? ''),
            tenantId:     $active,
            homeTenantId: $home,
        );
    }

    /**
     * 有效角色 = 直连角色 + 其所有祖先角色（子继承父，深度上限 5，防环）。
     *
     * 优先读 `PermissionCache`（`DataScope` 也会调用它，命中可省一次查库）；
     * `$useCache = false` 用于「正在构建缓存」的路径，避免自我递归。
     *
     * `$tenantId` 是**角色归属租户**（`sys_user.tenant_id`，不是超管切换后的生效租户）：
     * `role` 参与租户隔离（`TenantContext::TABLES`），而这里是直查路径，不自行收敛就与
     * `Role` 模型的全局作用域成了两套口径 —— 同一张表，模型查询带 `tenant_id`、这里全表扫。
     * 继承链本身跨不出租户（父角色只能从本租户选），限定后结果不变、扫描量按租户收敛。
     *
     * 传 null 时回退到「当前用户的归属租户」：业务层调用点都在请求内，不必自己取；
     * 登录链路（`buildContext()`）尚未建立身份，必须显式传入刚查出来的 `sys_user.tenant_id`。
     *
     * @param int|null $tenantId 角色归属租户；null = 取当前用户的 `homeTenantId`，取不到则不收敛
     */
    public static function effectiveRoleIds(int $userId, bool $useCache = true, ?int $tenantId = null): array
    {
        if ($useCache) {
            $cached = PermissionCache::load($userId);
            if ($cached !== null && isset($cached['roleIds'])) {
                return array_map('intval', (array)$cached['roleIds']);
            }
        }

        $direct = array_map('intval', Db::name('user_role')->where('user_id', $userId)->column('role_id'));
        if (!$direct) {
            return [];
        }

        // 已进回收站的角色不参与继承解析。
        // 租户收敛见方法注释：登录链路显式传值，业务层回退到「当前用户的归属租户」——
        // 超管切换租户后 `currentTenantId()` 是**生效**租户，拿它过滤角色会一个都查不到。
        if ($tenantId === null) {
            $current  = function_exists('request') ? request()?->user : null;
            $tenantId = $current instanceof UserContext && $current->id === $userId
                ? $current->homeTenantId
                : TenantContext::currentTenantId();
        }

        $roleQuery = SoftDelete::apply(Db::name('role'));
        if ($tenantId !== null) {
            $roleQuery->where('tenant_id', $tenantId);
        }
        $roleParent = $roleQuery->column('parent_id', 'id'); // id => parent_id

        $result = $direct;
        foreach ($direct as $roleId) {
            $current = $roleId;
            $guard = 0;
            while ($guard++ < 5 && isset($roleParent[$current]) && (int)$roleParent[$current] > 0) {
                $parent = (int)$roleParent[$current];
                if (in_array($parent, $result, true)) {
                    break; // 防环
                }
                $result[] = $parent;
                $current = $parent;
            }
        }

        return array_values(array_unique($result));
    }

    public static function hasSuperAdmin(array $roleIds): bool
    {
        if (!$roleIds) {
            return false;
        }
        // 软删的角色不能继续授予超管
        return SoftDelete::apply(Db::name('role'))->where('id', 'in', $roleIds)->where('code', 'super_admin')->count() > 0;
    }

    /** @return array<int,string> */
    public static function roleCodes(array $roleIds): array
    {
        if (!$roleIds) {
            return [];
        }
        return array_values(SoftDelete::apply(Db::name('role'))->where('id', 'in', $roleIds)->column('code'));
    }

    /** 有效权限节点（所有有效角色的 role_node 并集）。 */
    public static function permissions(array $roleIds): array
    {
        if (!$roleIds) {
            return [];
        }

        $nodes = array_values(array_unique(Db::name('role_node')->where('role_id', 'in', $roleIds)->column('node')));
        if (!$nodes) {
            return [];
        }

        // 菜单被删除（回收站）后其节点不再授权——否则「删了菜单」只是看不见入口，接口仍可调用。
        // 授权行刻意保留在 role_node 里，恢复菜单后即自动重新生效。
        $trashed = SoftDelete::onlyTrashed(Db::name('menu'))->column('node');
        if ($trashed) {
            $trashed = array_flip(array_map('strval', $trashed));
            $nodes   = array_values(array_filter($nodes, static fn ($node) => !isset($trashed[(string)$node])));
        }

        return $nodes;
    }
}
