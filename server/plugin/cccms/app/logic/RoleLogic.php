<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\RoleNode;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PageInput;
use plugin\cccms\support\PermissionCache;
use plugin\cccms\support\Tree;
use plugin\cccms\support\UniqueGuard;
use think\facade\Db;

/**
 * 角色管理逻辑（含继承与节点授权）。
 *
 * 查询统一走 `Role` / `RoleNode` 模型；角色表当前声明为**不参与**数据权限
 * （组织架构数据，见 `app/model/Role.php`），因此作用域是空操作 ——
 * 但入口统一后，将来若要给角色加隔离，只改模型声明即可生效。
 *
 * 需要看到全量数据的判定（存在性）用 `withoutGlobalScope()`（仍受租户边界约束）；
 * 唯一标识这类**全局索引**校验必须用 `withoutAllScopes()` 完全绕过租户作用域。
 */
final class RoleLogic
{
    /**
     * 可写字段白名单（防 Mass Assignment）。
     *
     * `nodes` 不是表列，是前端勾选的权限节点数组，由 `pullNodes()` 摘出来单独授权。
     * 刻意不含 `id` / `tenant_id` / `create_time` / `delete_time`。
     */
    private const FIELDS = ['name', 'code', 'data_scope', 'parent_id', 'sort', 'status', 'remark', 'nodes'];

    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除）；软删除过滤由模型层承担
        $query = !empty($params['trashed']) ? Role::onlyTrashed() : Role::newScopedQuery();
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        // 列头筛选支持多选，值形如 `1,0`
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        $dataScopes = FilterInput::ints($params['data_scope'] ?? null);
        if ($dataScopes !== []) {
            $query->whereIn('data_scope', $dataScopes);
        }
        // 左侧角色树选中节点后，只列出该角色及其所有下级
        if (!empty($params['node_id'])) {
            $query->whereIn('id', self::subtreeIds((int)$params['node_id']));
        }
        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /** 角色自身 + 所有下级角色 id */
    public static function subtreeIds(int $id): array
    {
        return Tree::subtreeIds(Role::withoutGlobalScope()->column('parent_id', 'id'), [$id]);
    }

    /** 角色树（含继承，供选择器）。 */
    public static function tree(): array
    {
        $all = Role::withoutGlobalScope()->order('sort', 'asc')->select()->toArray();

        return Tree::buildTree($all, 0);
    }

    public static function read(int $id): array
    {
        $role = self::assertExists($id);
        $nodes = self::nodes($id);

        return array_merge($role, $nodes);
    }

    public static function create(array $data): int
    {
        $data = FilterInput::only($data, self::FIELDS);

        self::assertUniqueCode((string)($data['code'] ?? ''), 0);

        $nodes = self::pullNodes($data);
        // 角色与授权节点同成败：只写进一半会出现「角色存在但无任何权限」
        $id = Db::transaction(static function () use ($data, $nodes): int {
            // 新增还没有归属，插入语句不需要数据权限条件
            $id = (int)Role::withoutGlobalScope()->insertGetId($data);
            self::assignNodes($id, $nodes);

            return $id;
        });

        // 角色的权限集合变了：让所有用户的权限缓存立即失效
        PermissionCache::bump();

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::FIELDS);

        $role = self::assertExists($id);
        if ($role['code'] === 'super_admin') {
            // 超管角色保护：code 不可改
            unset($data['code']);
        }
        if (!empty($data['code'])) {
            self::assertUniqueCode((string)$data['code'], $id);
        }
        // 只有显式带了 nodes 才重设节点（不带表示「不动节点」）
        $hasNodes = array_key_exists('nodes', $data);
        $nodes    = self::pullNodes($data);

        Db::transaction(static function () use ($data, $id, $hasNodes, $nodes): void {
            if ($data) {
                Role::where('id', $id)->update($data);
            }
            if ($hasNodes) {
                self::assignNodes($id, $nodes);
            }
        });

        PermissionCache::bump();
    }

    /**
     * 复制角色（含 `data_scope` 与**自身**节点授权）。
     *
     * 刻意**不复制子角色**：子角色自带 `parent_id` 指向源角色，一并复制会让两棵树纠缠。
     *
     * `data_scope` 必须显式复制：档位不随继承传递（见 `DataScope`），漏复制会让副本
     * 静默落到默认档「仅本人」，权限被无声收窄。
     *
     * 只复制**自身**节点（`sys_role_node` 里属于源角色的行），不含继承来的祖先节点 ——
     * 继承会在副本挂到同一父角色时自然生效，写死进来反而会在源角色调整后失效。
     *
     * @param array<string,mixed> $override 可覆盖 name / code / parent_id / sort / status
     */
    public static function copy(int $sourceId, array $override = []): int
    {
        $source = self::assertExists($sourceId);

        $parentId = array_key_exists('parent_id', $override)
            ? (int)$override['parent_id']
            : (int)$source['parent_id'];
        self::assertParentValid($parentId);

        $name = trim((string)($override['name'] ?? ''));
        if ($name === '') {
            $name = mb_substr($source['name'] . I18n::t('role.copy_suffix'), 0, 64);
        }

        $code = trim((string)($override['code'] ?? ''));
        if ($code === '') {
            $code = self::suggestCode((string)$source['code']);
        }
        self::assertUniqueCode($code, 0);

        $id = (int)Role::withoutGlobalScope()->insertGetId([
            'name'       => mb_substr($name, 0, 64),
            'code'       => mb_substr($code, 0, 64),
            'data_scope' => (int)$source['data_scope'],
            'parent_id'  => $parentId,
            'sort'       => self::intOr((int)$source['sort'], $override['sort'] ?? null),
            // 「另存为模板」＝ 复制成禁用角色：不影响任何人的权限，待需要时再启用
            'status'     => self::intOr((int)$source['status'], $override['status'] ?? null),
            'remark'     => (string)$source['remark'],
        ]);

        self::assignNodes($id, RoleNode::where('role_id', $sourceId)->column('node'));

        PermissionCache::bump();

        return $id;
    }

    /** 覆盖值为空（null / 空串）时取源角色的值，避免前端漏传把状态改成「禁用」 */
    private static function intOr(int $fallback, mixed $value): int
    {
        return ($value === null || $value === '') ? $fallback : (int)$value;
    }

    /** 由源标识派生一个未被占用的角色标识（`code_copy` / `code_copy2` …） */
    private static function suggestCode(string $sourceCode): string
    {
        $base = mb_substr($sourceCode, 0, 56);
        // 角色标识是**全局唯一索引**（uk_code），不随租户重复，因此这里必须完全绕过租户作用域；
        // 只看本租户会派生出已被别的租户占用的 code，插入时撞唯一键。
        $taken = Role::withoutAllScopes('uk_code 是全局唯一键，派生标识必须跨租户排重')->withTrashed()->column('code');

        for ($i = 1; $i <= 50; $i++) {
            $code = $base . ($i === 1 ? '_copy' : '_copy' . $i);
            if (!in_array($code, $taken, true)) {
                return $code;
            }
        }

        throw new ApiException(I18n::t('role.code_generate_failed'), 422);
    }

    /**
     * 校验父角色的合法性：存在且继承深度不超过 5 层。
     *
     * 深度口径与 `AuthService::effectiveRoleIds()` 的向上遍历上限一致：
     * 从父角色走到根节点的**步数**不得超过 5，否则副本的祖先链会被截断。
     *
     * 复制出来的是**新角色**、没有子角色，因此不可能成环；`$depth` 上限同时兜住了
     * 「历史数据里已存在环」的情况，不会死循环。
     */
    private static function assertParentValid(int $parentId): void
    {
        if ($parentId <= 0) {
            return;
        }

        $parents = Role::withoutGlobalScope()->column('parent_id', 'id');
        if (!isset($parents[$parentId])) {
            throw new ApiException(I18n::t('role.parent_not_found'), 422);
        }

        $current = $parentId;
        $depth   = 0;
        while ($current > 0) {
            if (++$depth > 5) {
                throw new ApiException(I18n::t('role.inherit_depth_exceeded'), 422);
            }
            $current = (int)($parents[$current] ?? 0);
        }
    }

    public static function delete(int $id): void
    {
        $role = self::assertExists($id);
        if ($role['code'] === 'super_admin') {
            throw new ApiException(I18n::t('role.cannot_delete_super'), 422);
        }
        // 有子角色则禁止删除（避免继承链断裂）
        if (Role::withoutGlobalScope()->where('parent_id', $id)->count() > 0) {
            throw new ApiException(I18n::t('role.has_children'), 422);
        }
        // 软删除：进回收站；role_node / user_role 保留，恢复后授权与分配原样回来。
        // 已软删的角色在 AuthService（登录/鉴权）与 DataScope（数据范围）里都会被过滤，
        // 因此不会继续授予任何权限。
        Role::destroy($id);
        PermissionCache::bump();
    }

    /** 角色的节点（含继承标记）。 */
    public static function nodes(int $id): array
    {
        self::assertExists($id);
        $own = RoleNode::where('role_id', $id)->column('node');
        $inherited = [];
        $parent = (int)Role::withoutGlobalScope()->where('id', $id)->value('parent_id');
        $guard = 0;
        while ($parent > 0 && $guard++ < 5) {
            $inherited = array_merge($inherited, RoleNode::where('role_id', $parent)->column('node'));
            $parent = (int)Role::withoutGlobalScope()->where('id', $parent)->value('parent_id');
        }

        return [
            'own'       => array_values(array_unique($own)),
            'inherited' => array_values(array_unique($inherited)),
        ];
    }

    /**
     * 取出并剔除「权限节点」相关键。
     *
     * - `nodes`：前端勾选的节点数组；
     * - `own` / `inherited`：`read()` 回显给前端的关联数据，编辑时会被原样带回来。
     *
     * 三者都不是 sys_role 的列，透传给 insert/update 会触发 think-orm 的
     * fields_strict 校验：`fields not exists: [nodes]`。
     *
     * @param array<string,mixed> $data 引用传入，调用后这些键已被剔除
     */
    private static function pullNodes(array &$data): array
    {
        $nodes = $data['nodes'] ?? [];
        unset($data['nodes'], $data['own'], $data['inherited']);

        if (is_string($nodes)) {
            $decoded = json_decode($nodes, true);
            $nodes   = is_array($decoded) ? $decoded : [];
        }

        return is_array($nodes) ? $nodes : [];
    }

    private static function assignNodes(int $roleId, array $nodes): void
    {
        RoleNode::where('role_id', $roleId)->delete();

        $rows = [];
        foreach (array_unique(array_map('strval', $nodes)) as $node) {
            if ($node !== '') {
                $rows[] = ['role_id' => $roleId, 'node' => $node];
            }
        }
        if ($rows !== []) {
            // 一次批量插入：勾选上百个节点时不再产生上百条 INSERT，缩短事务持锁时间
            RoleNode::insertAll($rows);
        }
    }

    private static function assertExists(int $id): array
    {
        $role = Role::withoutGlobalScope()->where('id', $id)->find();
        if (!$role) {
            throw new ApiException(I18n::t('role.not_found'), 404);
        }

        return $role->toArray();
    }

    private static function assertUniqueCode(string $code, int $excludeId): void
    {
        if ($code === '') {
            throw new ApiException(I18n::t('role.code_required'), 422);
        }

        UniqueGuard::assertFree(
            Role::class,
            'code',
            $code,
            $excludeId,
            'role.code_exists',
            'role.code_in_trash',
            ['code' => $code]
        );
    }
}
