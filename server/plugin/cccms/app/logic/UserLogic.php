<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\PageInput;
use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\app\model\UserDept;
use plugin\cccms\app\model\UserPost;
use plugin\cccms\app\model\UserRole;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PasswordPolicy;
use plugin\cccms\support\PermissionCache;
use plugin\cccms\support\SortInput;
use plugin\cccms\support\Tree;
use plugin\cccms\support\UserContext;
use think\facade\Db;
use Throwable;

/**
 * 用户管理逻辑。
 *
 * 数据权限**全部由模型层承担**，这里不再出现任何手写判定：
 *   - 行级范围：「仅本人」= id、「本部门」走 sys_user_dept → `BaseModel::scopeDataScope()`；
 *   - 出参字段规则（hidden / mask / encrypt）→ `BaseModel::toArray()`；
 *   - 入参字段剔除（不可见 / 不可改）→ `ScopedQuery` 在写库前统一处理。
 *
 * 需要看到**全量**数据的地方（存在性判断）显式用 `User::withoutGlobalScope()`
 * —— 它保留租户边界、只跳出数据权限；而 `username` 是**全局唯一索引**，
 * 这类唯一性校验必须用 `User::withoutAllScopes()` 连租户一起绕过，否则会漏判并写出脏索引。
 */
final class UserLogic
{
    /** 对外安全字段（不含 password）。 */
    private const SAFE_FIELDS = 'id,username,nickname,avatar,email,phone,status,remark,login_time,login_ip,create_time,update_time';

    /** 列头可排序列：前端 prop => 真实列名（未登记的列回落默认排序） */
    private const SORTABLE = [
        // 只开放「最近登录」：用户名/昵称/手机号这类标识列排序没有实际意义
        'login_time' => 'login_time',
    ];

    /**
     * 可写字段白名单（防 Mass Assignment）。
     *
     * 前三行是 `sys_user` 的可写列；`role_ids` / `dept_ids` / `post_ids` 不是表列，
     * 是前端提交的**关联数组**，由 `pull()` 摘出来交给 `assign()` 系列处理。
     * 刻意不含 `id` / `tenant_id` / `login_time` / `login_ip` / `create_time` / `delete_time`：
     * 它们要么由系统维护，要么会破坏数据归属与审计。
     */
    private const FIELDS = [
        'username', 'password', 'nickname', 'avatar', 'email', 'phone', 'status', 'remark',
        'role_ids', 'dept_ids', 'post_ids',
    ];

    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除），与正常列表共用同一套列。
        // 软删除过滤与行级数据范围都由模型层提供，这里只切换数据源。
        $query = !empty($params['trashed']) ? User::onlyTrashed() : User::newScopedQuery();
        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }
        if (!empty($params['nickname'])) {
            $query->where('nickname', 'like', '%' . $params['nickname'] . '%');
        }
        // 列头筛选支持多选，值形如 `1,0`
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        // 最后登录时间范围（列头时间筛选，值已归一化为 Y-m-d H:i:s）
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);
        if ($start !== '') {
            $query->where('login_time', '>=', $start);
        }
        if ($end !== '') {
            $query->where('login_time', '<=', $end);
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE);
        // 字段级规则（hidden / mask / encrypt）已由 User::toArray() 统一处理
        $list = $query->field(self::SAFE_FIELDS)->page($page, $limit)->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public static function read(int $id): array
    {
        // 数据权限已由模型作用域生效：越权时这里直接查不到
        $user = User::newScopedQuery()->where('id', $id)->field(self::SAFE_FIELDS)->find();
        if (!$user) {
            // 区分「真不存在」（404）与「存在但越权」（403）
            self::assertExists($id);
            throw new ApiException(I18n::t('user.no_permission_view'), 403);
        }

        // toArray() 里已统一应用字段级规则（hidden 不下发 / mask 掩码 / encrypt 密文），
        // 掩码值被表单回写的问题由 ScopedQuery 在写入侧剔除兜住
        $row = $user->toArray();

        $row['role_ids'] = array_map('intval', UserRole::where('user_id', $id)->column('role_id'));
        $row['dept_ids'] = array_map('intval', UserDept::where('user_id', $id)->column('dept_id'));
        $row['post_ids'] = array_map('intval', UserPost::where('user_id', $id)->column('post_id'));

        return $row;
    }

    public static function create(array $data): int
    {
        $data = FilterInput::only($data, self::FIELDS);

        self::assertUniqueUsername((string)($data['username'] ?? ''), 0);
        if (empty($data['password'])) {
            throw new ApiException(I18n::t('user.password_required'), 422);
        }
        self::assertPassword((string)$data['password'], ['username' => (string)($data['username'] ?? '')]);
        $data['password'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);

        // 字段级规则的入参剔除由 ScopedQuery 在写库前统一完成（见 app/model/ScopedQuery.php）

        $roleIds = self::pull($data, 'role_ids') ?? [];
        $deptIds = self::pull($data, 'dept_ids') ?? [];
        $postIds = self::pull($data, 'post_ids') ?? [];

        // 主表与三张关系表必须同成败，否则会留下「用户存在但没有任何角色」的半截数据
        $id = Db::transaction(static function () use ($data, $roleIds, $deptIds, $postIds): int {
            // 新增还没有「归属」，插入语句不需要数据权限条件
            $id = (int)User::withoutGlobalScope()->insertGetId($data);
            self::assign($id, $roleIds, $deptIds, $postIds);

            return $id;
        });

        // 角色 / 部门 / 岗位关系变化会影响该用户的权限与数据范围缓存
        PermissionCache::bump();

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::FIELDS);

        self::assertExists($id);
        self::assertInScope($id);
        if (!empty($data['username'])) {
            self::assertUniqueUsername((string)$data['username'], $id);
        }
        if (!empty($data['password'])) {
            // 编辑用户同样会改密码，必须过同一套策略，否则这里就是绕过策略的缺口
            self::assertPassword((string)$data['password'], self::identityOf($id, $data));
            $data['password'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $roleIds = self::pull($data, 'role_ids');
        $deptIds = self::pull($data, 'dept_ids');
        $postIds = self::pull($data, 'post_ids');

        Db::transaction(static function () use ($data, $id, $roleIds, $deptIds, $postIds): void {
            User::newScopedQuery()->where('id', $id)->update($data);

            // 只有显式提供了关联数组时才重设（未提供 = 保持原样，导入「留空不改动」依赖此语义）；
            // 提供了空数组 = 清空对应关联（表单编辑的默认行为）。
            if ($roleIds !== null) {
                self::assignRoles($id, $roleIds);
            }
            if ($deptIds !== null) {
                self::assignDepts($id, $deptIds);
            }
            if ($postIds !== null) {
                self::assignPosts($id, $postIds);
            }
        });

        PermissionCache::bump();
    }

    public static function delete(int $id, UserContext $operator): void
    {
        if ($id === $operator->id) {
            throw new ApiException(I18n::t('user.cannot_delete_self'), 422);
        }
        self::assertExists($id);
        self::assertInScope($id);

        $roleIds = array_map('intval', UserRole::where('user_id', $id)->column('role_id'));
        $isSuper = $roleIds !== []
            && Role::whereIn('id', $roleIds)->where('code', 'super_admin')->count() > 0;
        if ($isSuper) {
            throw new ApiException(I18n::t('user.cannot_delete_super'), 422);
        }

        // 软删除：进回收站；关联表刻意保留，恢复后角色/部门/岗位原样回来。
        // destroy() 由 SoftDelete trait 提供，作用域仍然生效（越权 id 匹配不到行）
        User::destroy($id);
        PermissionCache::bump();
    }

    public static function resetPassword(int $id, string $password): void
    {
        self::assertPassword($password, self::identityOf($id));
        self::assertExists($id);
        self::assertInScope($id);

        User::newScopedQuery()
            ->where('id', $id)
            ->update(['password' => password_hash($password, PASSWORD_BCRYPT)]);
    }

    // ---- 批量操作 ----
    // 统一语义：**只作用于当前数据范围内的行**，范围外的 id 被跳过并回报，
    // 不抛异常整批失败 —— 管理员选了 10 条，其中 1 条越权，另 9 条仍应生效。

    /**
     * 批量启用 / 禁用。
     *
     * 禁用会让对方**下一次请求**即 401（`AuthService::buildContext()` 每请求实时查状态），
     * 无需额外拉黑令牌。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchStatus(array $ids, int $status, UserContext $operator): array
    {
        $ids = self::batchIds($ids);
        $inScope = self::scopedIds($ids);

        // 自己与超管账号不参与批量禁用：与单条删除同一套保护，避免误操作把自己锁在门外
        if ($status !== 1) {
            $inScope = array_values(array_diff($inScope, self::protectedIds($inScope, $operator)));
        }

        $affected = $inScope === [] ? 0 : User::newScopedQuery()->whereIn('id', $inScope)->update(['status' => $status]);
        if ($affected > 0) {
            // 状态影响鉴权（禁用立即生效），缓存要跟着失效
            PermissionCache::bump();
        }

        return ['affected' => $affected, 'skipped' => array_values(array_diff($ids, $inScope))];
    }

    /**
     * 批量删除（软删）。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchDelete(array $ids, UserContext $operator): array
    {
        $ids = self::batchIds($ids);
        $inScope = array_values(array_diff(self::scopedIds($ids), self::protectedIds($ids, $operator)));
        $skipped = array_values(array_diff($ids, $inScope));

        if ($inScope === []) {
            return ['affected' => 0, 'skipped' => $skipped];
        }

        // 作用域仍然生效（越权 id 匹配不到行）；关联表刻意保留，恢复后角色/部门/岗位原样回来
        $affected = User::destroy($inScope);
        PermissionCache::bump();

        return ['affected' => $affected, 'skipped' => $skipped];
    }

    /**
     * 批量分配角色 / 部门 / 岗位。
     *
     * 语义是**整体替换**（与单条编辑表单一致）：传了哪个键就重设哪个关联，未传的保持原样。
     * 传空数组 = 清空该关联。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchAssign(array $ids, array $data): array
    {
        $ids = self::batchIds($ids);
        $inScope = self::scopedIds($ids);
        $skipped = array_values(array_diff($ids, $inScope));

        if ($inScope === []) {
            return ['affected' => 0, 'skipped' => $skipped];
        }

        $roleIds = self::pull($data, 'role_ids');
        $deptIds = self::pull($data, 'dept_ids');
        $postIds = self::pull($data, 'post_ids');
        if ($roleIds === null && $deptIds === null && $postIds === null) {
            throw new ApiException(I18n::t('user.assign_target_required'), 422);
        }

        // 归属校验只做一次：三个维度的输入对整批用户完全相同，循环内重复校验是 N+1
        if ($roleIds !== null) {
            self::assertOwnedInTenant(Role::class, $roleIds, 'user.label_role');
        }
        if ($deptIds !== null) {
            self::assertOwnedInTenant(Dept::class, $deptIds, 'user.label_dept');
        }
        if ($postIds !== null) {
            self::assertOwnedInTenant(Post::class, $postIds, 'user.label_post');
        }

        foreach ($inScope as $id) {
            if ($roleIds !== null) {
                self::syncRoles($id, $roleIds);
            }
            if ($deptIds !== null) {
                self::syncDepts($id, $deptIds);
            }
            if ($postIds !== null) {
                self::syncPosts($id, $postIds);
            }
        }

        // 角色 / 部门 / 岗位关系变化会影响数据范围与权限缓存
        PermissionCache::bump();

        return ['affected' => count($inScope), 'skipped' => $skipped];
    }

    /**
     * 当前数据范围内实际可见的 id（越权与不存在的 id 都会落选）。
     *
     * @param  int[] $ids
     * @return int[]
     */
    private static function scopedIds(array $ids): array
    {
        return array_map('intval', User::newScopedQuery()->whereIn('id', $ids)->column('id'));
    }

    /**
     * 从给定 id 中挑出「受保护、不允许批量禁用 / 删除」的那些（自己 + 超管）。
     *
     * @param  int[] $ids
     * @return int[]
     */
    private static function protectedIds(array $ids, UserContext $operator): array
    {
        if ($ids === []) {
            return [];
        }

        $superRoleIds = Role::withoutGlobalScope()->where('code', 'super_admin')->column('id');
        $superIds = $superRoleIds === []
            ? []
            : array_map('intval', UserRole::whereIn('role_id', $superRoleIds)->whereIn('user_id', $ids)->column('user_id'));

        $protected = $superIds;
        if (in_array($operator->id, $ids, true)) {
            $protected[] = $operator->id;
        }

        return array_values(array_unique($protected));
    }

    /** 规范化批量 id：去重、去非正整数 */
    private static function batchIds(array $ids): array
    {
        return Tree::batchIds($ids);
    }

    /**
     * 单条记录的数据范围校验。
     *
     * 「查不到」与「不存在」在响应上要区分开：越权给 403，而不是伪装成 404。
     * 范围本身由 User 模型的全局作用域注入（与控制器传入的 `$request->user` 一致）。
     */
    private static function assertInScope(int $id): void
    {
        if (User::newScopedQuery()->where('id', $id)->count() === 0) {
            throw new ApiException(I18n::t('user.no_permission_operate'), 403);
        }
    }

    /**
     * 存在性校验：必须看到**全量**数据（含其他部门、含回收站），显式跳出数据权限。
     */
    private static function assertExists(int $id): void
    {
        if (!User::withoutGlobalScope()->where('id', $id)->find()) {
            throw new ApiException(I18n::t('user.not_found'), 404);
        }
    }

    /**
     * 密码强度校验：策略见 PasswordPolicy。
     *
     * 传入身份信息是为了拒绝「密码包含用户名/昵称/邮箱」这类可猜口令；
     * 调用点能拿到多少就传多少（重置密码时只有用户 id，需从库里补身份）。
     *
     * @param array<string,mixed> $identity
     */
    private static function assertPassword(string $password, array $identity = []): void
    {
        PasswordPolicy::assertValid($password, $identity);
    }

    /**
     * 组装密码策略所需的身份信息（用户名 / 昵称 / 邮箱）。
     *
     * 表单提交的改动尚未落库，所以**入参优先**；入参没带的字段再用库里的存量值补齐
     * （重置密码接口只传 id，全靠这一步）。查库失败时不阻断，退化为「只校验纯口令强度」。
     *
     * @param array<string,mixed> $data 待写入的数据（优先取其中的身份字段）
     * @return array<string,string>
     */
    private static function identityOf(int $id, array $data = []): array
    {
        $identity = [
            'username' => trim((string)($data['username'] ?? '')),
            'nickname' => trim((string)($data['nickname'] ?? '')),
            'email'    => trim((string)($data['email'] ?? '')),
        ];

        if ($id <= 0 || ($identity['username'] !== '' && $identity['nickname'] !== '' && $identity['email'] !== '')) {
            return $identity;
        }

        try {
            $row = User::withoutGlobalScope()
                ->field(['username', 'nickname', 'email'])
                ->where('id', $id)
                ->find();
        } catch (Throwable) {
            return $identity;
        }

        foreach (['username', 'nickname', 'email'] as $key) {
            if ($identity[$key] === '') {
                $identity[$key] = trim((string)($row[$key] ?? ''));
            }
        }

        return $identity;
    }

    private static function assign(int $userId, array $roleIds, array $deptIds, array $postIds): void
    {
        self::assignRoles($userId, $roleIds);
        self::assignDepts($userId, $deptIds);
        self::assignPosts($userId, $postIds);
    }

    private static function assignRoles(int $userId, array $roleIds): void
    {
        self::assertOwnedInTenant(Role::class, $roleIds, 'user.label_role');
        self::syncRoles($userId, $roleIds);
    }

    private static function assignDepts(int $userId, array $deptIds): void
    {
        self::assertOwnedInTenant(Dept::class, $deptIds, 'user.label_dept');
        self::syncDepts($userId, $deptIds);
    }

    private static function assignPosts(int $userId, array $postIds): void
    {
        self::assertOwnedInTenant(Post::class, $postIds, 'user.label_post');
        self::syncPosts($userId, $postIds);
    }

    /** 纯写入：删除旧关联 + 批量插入（归属校验由调用方负责，避免批量分配时 N+1） */
    private static function syncRoles(int $userId, array $roleIds): void
    {
        UserRole::where('user_id', $userId)->delete();
        $rows = [];
        foreach (self::positiveIds($roleIds) as $rid) {
            $rows[] = ['user_id' => $userId, 'role_id' => $rid];
        }
        if ($rows !== []) {
            UserRole::insertAll($rows);
        }
    }

    private static function syncDepts(int $userId, array $deptIds): void
    {
        UserDept::where('user_id', $userId)->delete();
        $rows = [];
        foreach (self::positiveIds($deptIds) as $did) {
            $rows[] = ['user_id' => $userId, 'dept_id' => $did];
        }
        if ($rows !== []) {
            UserDept::insertAll($rows);
        }
    }

    private static function syncPosts(int $userId, array $postIds): void
    {
        UserPost::where('user_id', $userId)->delete();
        $rows = [];
        foreach (self::positiveIds($postIds) as $pid) {
            $rows[] = ['user_id' => $userId, 'post_id' => $pid];
        }
        if ($rows !== []) {
            UserPost::insertAll($rows);
        }
    }

    /** @return int[] 归一化关联 id：去重、去非正整数 */
    private static function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * 校验被引用的角色 / 部门 / 岗位都存在于**当前租户**内。
     *
     * 关联表（sys_user_role / sys_user_dept / sys_user_post）是平台级的，没有 tenant_id 列，
     * 无法靠列隔离，因此必须在写关联前挡住跨租户引用 ——
     * 否则 A 租户的管理员只要猜到 id，就能把 B 租户的角色挂到自己人身上（提权）。
     *
     * 查询走 `newScopedQuery()`：带租户作用域，跨租户的 id 自然查不到。
     *
     * @param class-string $model
     * @param array<int,mixed> $ids
     */
    private static function assertOwnedInTenant(string $model, array $ids, string $labelKey): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return;
        }

        $found = array_map('intval', $model::newScopedQuery()->whereIn('id', $ids)->column('id'));
        if (count($found) !== count($ids)) {
            throw new ApiException(I18n::t('user.ref_not_in_tenant', ['label' => I18n::t($labelKey)]), 422);
        }
    }

    private static function assertUniqueUsername(string $username, int $excludeId): void
    {
        if ($username === '') {
            throw new ApiException(I18n::t('user.username_required'), 422);
        }

        // 唯一索引不做软删特例：回收站里的用户仍占用用户名，这里给出可读提示。
        // uk_username 是**全局唯一索引**（用户名不随租户重复），必须完全绕过租户作用域，
        // 否则「别的租户已占用」会被漏判，插入时撞唯一键；
        // withTrashed() 才能把回收站里的账号也算进来。
        $q = User::withoutAllScopes('uk_username 是全局唯一键，跨租户查重才不会写出撞索引的账号')
            ->withTrashed()
            ->where('username', $username);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        $exist = $q->find();
        if ($exist) {
            throw new ApiException(
                !empty($exist->delete_time)
                    ? I18n::t('user.username_in_trash', ['username' => $username])
                    : I18n::t('user.username_exists'),
                422
            );
        }
    }

    /**
     * 取出并移除关联数组字段。
     *
     * @return array<int,mixed>|null null = 键未提供（更新时表示「不改动」）；数组 = 已提供（空数组 = 清空）
     */
    private static function pull(array &$data, string $key): ?array
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        unset($data[$key]);
        return is_array($value) ? array_values($value) : [];
    }
}
