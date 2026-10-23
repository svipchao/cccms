<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\Environment;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PermissionCache;
use plugin\cccms\support\Slug;
use plugin\cccms\support\SoftDelete;
use plugin\cccms\support\TenantContext;
use plugin\cccms\support\Tree;
use plugin\cccms\support\UserContext;
use think\facade\Db;

/** 菜单 / 权限节点逻辑。 */
final class MenuLogic
{
    /**
     * 可写字段白名单（防 Mass Assignment）。
     *
     * 刻意不含 `id` / `create_time` / `update_time` / `delete_time`：
     * 菜单是**平台级**资源，没有 `tenant_id`，作用域与租户列也不允许经接口改写。
     */
    private const FIELDS = [
        'parent_id', 'type', 'title', 'path', 'component', 'icon', 'sort',
        'node', 'status', 'keep_alive', 'remark',
    ];

    /**
     * 平台级模块 slug 前缀：这些模块的菜单与按钮只在**平台租户**下可见。
     *
     * 用前缀匹配可同时命中菜单 slug（`cccms:tenant`）与其按钮 slug（`cccms:tenant:index` 等）。
     * 按钮节点是 perm-scan 从控制器注解生成的、不在 `db/menu.php` 里声明，
     * 只有前缀匹配才能把按钮也一并藏掉，避免「菜单没了、按钮节点残留」。
     */
    private const PLATFORM_ONLY_PREFIXES = ['cccms:tenant'];

    /**
     * 仅本地开发环境可见的模块 slug 前缀。
     *
     * 「接口文档」是开发期的契约视图：线上既不该给业务用户看（一份完整攻击面清单），
     * 也不该出现在侧边栏里占位置。判定口径与危险功能门禁一致 —— `Environment::isDevLayout()`
     * （仓库根存在 `frontend/package.json`，即完整源码仓库），因此**线上构建产物形态下自动隐藏**，
     * 无需改库、无需手工停用菜单。
     *
     * 同样用前缀匹配把 perm-scan 生成的按钮节点（`cccms:apidoc:index` 等）一并藏掉；
     * 菜单管理（`tree()`）不过滤，保留节点便于排查与回收站恢复。
     */
    private const DEV_ONLY_PREFIXES = ['cccms:apidoc'];

    /**
     * 全量菜单树（菜单管理用）。
     *
     * `$trashed=true` 时返回**平铺**的已删节点（且不按 status 过滤，隐藏节点也要能恢复）：
     * 回收站里的节点，其父节点可能还活着，拼树会漏掉「父未删、子已删」的节点。
     */
    public static function tree(bool $trashed = false): array
    {
        $query = SoftDelete::scope(Db::name('menu'), $trashed)
            ->order('sort', 'asc')
            ->order('id', 'asc');
        if (!$trashed) {
            $query->where('status', 1);
        }

        $all = $query->select()->toArray();

        return $trashed ? $all : self::localizeTitles(Tree::buildTree($all, 0));
    }

    /**
     * 当前用户可见的菜单树（前端动态路由）。
     *
     * 切到非平台租户后隐藏「平台级」模块（见 `PLATFORM_ONLY_PREFIXES`）——超管也不例外：
     * 否则超管切到租户后仍能看到「租户管理」入口，点进去才被 `TenantLogic::assertPlatformAdmin()`
     * 403，体验割裂。前端据此不注册对应动态路由，手动敲 URL 也落 404；
     * 后端接口的 `assertPlatformAdmin` 兜底仍在，不依赖前端。
     *
     * 另外过滤「仅本地开发环境」的模块（见 `DEV_ONLY_PREFIXES`）：菜单树是前端动态路由的唯一来源，
     * 线上不返回该节点即等于既不注册路由、也不显示入口。
     */
    public static function userTree(UserContext $user): array
    {
        $all = SoftDelete::apply(Db::name('menu'))
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()->toArray();

        if (!Environment::isDevLayout()) {
            $all = array_values(array_filter($all, static fn (array $item): bool => !self::isDevOnly($item)));
        }

        if ($user->tenantId !== TenantContext::PLATFORM_ID) {
            $all = array_values(array_filter($all, static fn (array $item): bool => !self::isPlatformOnly($item)));
        }

        if ($user->isSuperAdmin()) {
            return self::localizeTitles(Tree::buildTree($all, 0));
        }

        $nodes = array_flip($user->permissions);
        // 保留目录/菜单：其自身 node 在权限内，或其下有可见按钮/菜单
        $visible = [];
        foreach ($all as $item) {
            $type = (int)$item['type'];
            $node = (string)$item['node'];
            if ($type === 3) {
                if (isset($nodes[$node])) {
                    $visible[(int)$item['id']] = true;
                }
            } else {
                if ($node !== '' && isset($nodes[$node])) {
                    $visible[(int)$item['id']] = true;
                }
            }
        }
        // 向上传播：有可见子节点的祖先也可见。
        // 逐节点沿父链上溯（O(节点数 × 深度)），代替原来「每轮全表扫描、直到不再变化」的 O(n²)：
        // 按钮节点上千时，登录后的首个菜单请求会因此明显变慢
        $parentOf = [];
        foreach ($all as $item) {
            $parentOf[(int)$item['id']] = (int)$item['parent_id'];
        }
        foreach (array_keys($visible) as $id) {
            $pid   = $parentOf[(int)$id] ?? 0;
            $guard = 0;
            // guard 防脏数据成环（parent_id 互指）导致死循环
            while ($pid !== 0 && !isset($visible[$pid]) && $guard++ < 64) {
                $visible[$pid] = true;
                $pid           = $parentOf[$pid] ?? 0;
            }
        }

        $filtered = array_values(array_filter($all, fn ($i) => isset($visible[(int)$i['id']])));
        return self::localizeTitles(Tree::buildTree($filtered, 0));
    }

    /** 节点是否属于平台级模块（只应出现在平台租户的菜单里） */
    private static function isPlatformOnly(array $item): bool
    {
        return self::hasPrefix((string)($item['node'] ?? ''), self::PLATFORM_ONLY_PREFIXES);
    }

    /** 节点是否属于「仅本地开发环境可见」的模块 */
    private static function isDevOnly(array $item): bool
    {
        return self::hasPrefix((string)($item['node'] ?? ''), self::DEV_ONLY_PREFIXES);
    }

    /** slug 前缀命中（同时覆盖菜单 `x` 与其按钮 `x:action`），口径见 `Slug::belongsTo()` */
    private static function hasPrefix(string $node, array $prefixes): bool
    {
        return Slug::belongsTo($node, $prefixes);
    }

    /**
     * 菜单 / 节点标题国际化（递归，含 children）。
     *
     * 内置节点在 `lang/{locale}/menu.php` 里有 key → 按当前请求语言覆盖 `title`；
     * 管理员自建的菜单没有 key（属于用户录入数据）→ 原样返回，不翻译。
     * DB 里的中文 title 始终是权威兜底值，语言包缺失不会导致标题变空。
     */
    private static function localizeTitles(array $nodes): array
    {
        foreach ($nodes as &$node) {
            $slug = (string)($node['node'] ?? '');
            if ($slug !== '' && I18n::has('menu.' . $slug)) {
                $node['title'] = I18n::t('menu.' . $slug);
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = self::localizeTitles($node['children']);
            }
        }
        unset($node);

        return $nodes;
    }

    public static function create(array $data): int
    {
        $data = FilterInput::only($data, self::FIELDS);

        $data['node'] = $data['node'] ?? '';
        $id = (int)Db::name('menu')->insertGetId($data);
        // 菜单即权限节点：变更后所有用户的可见菜单 / 权限集合都要重新计算
        PermissionCache::bump();

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::FIELDS);

        if (!SoftDelete::apply(Db::name('menu'))->where('id', $id)->find()) {
            throw new ApiException(I18n::t('menu.not_found'), 404);
        }
        Db::name('menu')->where('id', $id)->update($data);
        PermissionCache::bump();
    }

    public static function delete(int $id): void
    {
        if (SoftDelete::apply(Db::name('menu'))->where('parent_id', $id)->count() > 0) {
            throw new ApiException(I18n::t('menu.has_children'), 422);
        }

        // 软删除：进回收站；role_node 授权刻意保留，恢复后授权原样回来。
        // 授权侧（AuthService::permissions）会把「回收站里的菜单节点」排除，所以删了就是真的没权限。
        // 「彻底删除」时才清理 role_node（见 RecycleLogic::forceDelete）。
        SoftDelete::remove(Db::name('menu'), $id);
        PermissionCache::bump();
    }

}
