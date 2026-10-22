<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Tenant;
use plugin\cccms\app\model\User;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\OnlineSession;
use plugin\cccms\support\SortInput;
use plugin\cccms\support\TenantContext;
use plugin\cccms\support\TenantDomain;
use plugin\cccms\support\TokenService;
use plugin\cccms\support\UserContext;

/**
 * 租户管理逻辑。
 *
 * 三件事：
 *   1. **平台侧**的租户档案 CRUD（本表是平台级元数据，只有平台超管能碰，见 `assertPlatformAdmin()`）；
 *   2. 超管的「切换租户」——重签一份带 `tid` 声明的令牌，而不是在服务端记状态；
 *   3. 顶栏切换器的候选列表（含虚拟的「平台」条目）。
 *
 * 注意本类**不负责**业务数据的隔离：那是 `TenantContext` + 模型作用域 + 直查构造器
 * （`TenantContext::table()`）三处协同完成的，见 docs/06-数据权限。
 */
final class TenantLogic
{
    /** 允许写入的字段（白名单） */
    private const FIELDS = ['name', 'code', 'domain', 'contact', 'phone', 'status', 'expire_at', 'remark'];

    /**
     * 列头可排序列：前端 prop => 真实列名。
     *
     * `user_count` 是逐行统计出来的，不在表里，故意不登记；
     * 平台虚拟行（id=0）始终置顶，排序只作用于真实租户行。
     */
    private const SORTABLE = [
        // 到期时间：管理员最关心的「谁快过期了」
        'expire_at' => 'expire_at',
    ];

    /**
     * 租户列表（分页）。
     *
     * 平台租户（id=0）在库里没有行，这里**只在不带筛选条件的第一页**合成一条只读行，
     * 让管理员一眼看到「平台」这个档位确实存在、且当前账号归属哪里；
     * 带筛选条件时不合成，避免「搜不到却还在列表里」的错觉。
     */
    public static function paginate(array $params): array
    {
        $query = Tenant::newScopedQuery();

        // 文本列头筛选：各列独立生效，多列之间是 AND
        $texts = ['name', 'code', 'contact', 'phone'];
        $hasText = false;
        foreach ($texts as $field) {
            $value = trim((string)($params[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, 'like', '%' . $value . '%');
                $hasText = true;
            }
        }
        // 列头筛选支持多选，值形如 `1,0`
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        // 到期时间范围（列头时间筛选，值已归一化为 Y-m-d H:i:s）
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);
        if ($start !== '') {
            $query->where('expire_at', '>=', $start);
        }
        if ($end !== '') {
            $query->where('expire_at', '<=', $end);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE, 'id', 'asc', 'asc');
        $list = $query->page($page, $limit)->select()->toArray();

        $counts = self::userCounts(array_column($list, 'id'));
        foreach ($list as &$row) {
            $row['is_platform'] = false;
            $row['user_count']  = $counts[(int)$row['id']] ?? 0;
        }
        unset($row);

        // 带筛选条件时不合成平台行，避免「筛不到却还在列表里」的错觉
        if ($page === 1 && !$hasText && $statuses === [] && $start === '' && $end === '') {
            $total++;
            array_unshift($list, self::platformRow());
        }

        return ['total' => $total, 'list' => $list];
    }

    /** 单个租户（平台租户返回合成的只读行） */
    public static function read(int $id): array
    {
        if ($id === TenantContext::PLATFORM_ID) {
            return self::platformRow();
        }

        $row = Tenant::where('id', $id)->find();
        if (!$row) {
            throw new ApiException(I18n::t('tenant.not_found'), 404);
        }

        $data = $row->toArray();
        $data['is_platform'] = false;
        $data['user_count']  = self::userCounts([$id])[$id] ?? 0;

        return $data;
    }

    public static function create(array $data): int
    {
        $payload = self::pick($data);
        self::assertFields($payload, true);
        self::assertUniqueCode((string)$payload['code'], 0);
        self::assertUniqueDomain((string)($payload['domain'] ?? ''), 0);

        $id = (int)Tenant::insertGetId($payload);
        TenantDomain::flush();

        return $id;
    }

    public static function update(int $id, array $data): void
    {
        self::assertNotPlatform($id, 'tenant.cannot_edit_platform');

        if (!Tenant::where('id', $id)->find()) {
            throw new ApiException(I18n::t('tenant.not_found'), 404);
        }

        $payload = self::pick($data);
        self::assertFields($payload, false);
        if (isset($payload['code'])) {
            self::assertUniqueCode((string)$payload['code'], $id);
        }
        // domain 允许被清空（置 null），此时 isset() 为 false，必须用 array_key_exists
        if (array_key_exists('domain', $payload)) {
            self::assertUniqueDomain((string)($payload['domain'] ?? ''), $id);
        }
        if ($payload === []) {
            return;
        }

        Tenant::where('id', $id)->update($payload);
        TenantDomain::flush();
    }

    /**
     * 删除租户（软删）。
     *
     * 租户下还有账号（含回收站）时**拒绝**：租户一删，那些账号既进不来也管不了，
     * 只能靠手工改库收拾。宁可让管理员先把人处理干净。
     */
    public static function delete(int $id): void
    {
        self::assertNotPlatform($id, 'tenant.cannot_delete_platform');

        if (!Tenant::where('id', $id)->find()) {
            throw new ApiException(I18n::t('tenant.not_found'), 404);
        }

        // 含回收站一起算：软删的账号仍然归属这个租户，租户被删掉就会变成没人能管的孤儿
        $users = self::userCounts([$id])[$id] ?? 0;
        if ($users > 0) {
            throw new ApiException(I18n::t('tenant.has_users', ['count' => $users]), 422);
        }

        Tenant::destroy($id);
    }

    /**
     * 顶栏切换器的候选：平台（虚拟） + 全部启用中的租户。
     *
     * 非超管返回空数组 —— 切换租户是超管专属能力，普通账号的租户由账号归属决定，
     * 令牌里的 `tid` 与归属不一致时 `AuthService::buildContext()` 会直接 401。
     *
     * `$domainTenantId` 非空表示「本次请求来自该租户的自定义域名」：此时**只返回这一个租户**，
     * 平台条目与其它租户一律不出现 —— 从入口上就断掉「在客户域名下选平台」的路径。
     */
    public static function options(UserContext $user, ?int $domainTenantId = null): array
    {
        if (!$user->isSuperAdmin()) {
            return [];
        }

        $rows = Tenant::newScopedQuery()
            ->field(['id', 'name', 'code', 'status', 'expire_at'])
            ->where('status', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $now = date('Y-m-d H:i:s');

        if ($domainTenantId !== null) {
            foreach ($rows as $row) {
                if ((int)$row['id'] !== $domainTenantId) {
                    continue;
                }

                $expire = (string)($row['expire_at'] ?? '');
                if ($expire !== '' && $expire <= $now) {
                    return []; // 域名所属租户已过期：一个候选都不给
                }

                return [self::optionRow($row, $user->tenantId)];
            }

            return [];
        }

        $list = [self::platformRow()];
        $list[0]['current'] = $user->tenantId === TenantContext::PLATFORM_ID;

        foreach ($rows as $row) {
            $expire = (string)($row['expire_at'] ?? '');
            if ($expire !== '' && $expire <= $now) {
                continue; // 已过期：`TenantContext::usable()` 会判不可用，不进候选
            }
            $list[] = self::optionRow($row, $user->tenantId);
        }

        return $list;
    }

    /**
     * 候选行的统一形状（平台行与租户行字段必须一致，前端只认一套）。
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function optionRow(array $row, int $currentTenantId): array
    {
        return [
            'id'          => (int)$row['id'],
            'name'        => (string)$row['name'],
            'code'        => (string)$row['code'],
            'is_platform' => false,
            'current'     => (int)$row['id'] === $currentTenantId,
            'expire_at'   => (string)($row['expire_at'] ?? ''),
        ];
    }

    /**
     * 超管切换生效租户：用新的 `tid` **重签令牌**，并迁移在线会话。
     *
     * 为什么重签而不是在服务端存「当前租户」：租户声明必须随令牌走，
     * 否则多实例 / 多进程下会各自维护一份状态。代价是切换后旧令牌仍然有效
     * （它声明的还是旧租户），这是可接受的 —— 两个租户本来都在该超管的权限内。
     *
     * @param string $jti            当前令牌的 jti（用于把在线会话迁到新令牌）
     * @param int|null $domainTenantId 本次请求命中的自定义域名租户；非空时只允许停在该租户
     * @return array{token:array<string,mixed>,user:array<string,mixed>}
     */
    public static function switchTo(
        UserContext $user,
        int $tenantId,
        string $jti = '',
        ?int $domainTenantId = null
    ): array {
        self::assertSuperAdmin($user);

        // 自定义域名访问：只能停在该域名所属租户，切平台与切别的租户一律拒绝。
        // 这是需求「自定义域名下不能选择平台登录」的落点之一（另一处是 options()）。
        if ($domainTenantId !== null && $tenantId !== $domainTenantId) {
            throw new ApiException(I18n::t('tenant.domain_locked'), 403);
        }

        if ($tenantId !== TenantContext::PLATFORM_ID && !TenantContext::usable($tenantId)) {
            throw new ApiException(I18n::t('tenant.not_usable'), 422);
        }

        $context = AuthService::buildContext($user->id, $tenantId);
        if ($context === null) {
            throw new ApiException(I18n::t('tenant.not_usable'), 422);
        }

        $token = TokenService::issue($user->id, ['tid' => $context->tenantId]);

        // 在线列表 / 强制下线都以 jti 为准：不迁移的话管理员踢的是已经废弃的旧令牌
        OnlineSession::rotate($jti, (string)$token['jti'], (int)$token['expires_at']);

        return ['token' => $token, 'user' => AuthLogic::profile($context)];
    }

    // ------------------------------------------------------------------
    // 私有
    // ------------------------------------------------------------------

    /** 租户管理是平台级动作：只有**当前处于平台租户**的超管能操作 */
    public static function assertPlatformAdmin(UserContext $user): void
    {
        self::assertSuperAdmin($user);

        if ($user->tenantId !== TenantContext::PLATFORM_ID) {
            throw new ApiException(I18n::t('tenant.platform_only'), 403);
        }
    }

    private static function assertSuperAdmin(UserContext $user): void
    {
        if (!$user->isSuperAdmin()) {
            throw new ApiException(I18n::t('tenant.super_only'), 403);
        }
    }

    private static function assertNotPlatform(int $id, string $messageKey): void
    {
        if ($id === TenantContext::PLATFORM_ID) {
            throw new ApiException(I18n::t($messageKey), 422);
        }
    }

    /**
     * 平台租户的只读合成行（库里没有这一行，见 `TenantContext::PLATFORM_ID`）。
     *
     * @return array<string,mixed>
     */
    private static function platformRow(): array
    {
        return [
            'id'          => TenantContext::PLATFORM_ID,
            'name'        => I18n::t('tenant.platform_name'),
            'code'        => 'platform',
            'contact'     => '',
            'phone'       => '',
            'status'      => 1,
            'expire_at'   => null,
            'remark'      => I18n::t('tenant.platform_remark'),
            'create_time' => null,
            'update_time' => null,
            'is_platform' => true,
            'user_count'  => self::userCounts([TenantContext::PLATFORM_ID])[TenantContext::PLATFORM_ID] ?? 0,
        ];
    }

    /**
     * 批量取「租户 => 账号数」（**含回收站**，用于列表展示与删除前的占用判断）。
     *
     * 刻意用 `withoutAllScopes()` + `withTrashed()` 而不是 `Db::name()` 直查：
     * 这是一次**跨租户**的统计（平台侧看全局），要一次跳出数据档位与租户边界，
     * 同时把回收站里的账号一起算上 —— 它们仍然归属该租户，漏掉会误删租户。
     *
     * @param  array<int,mixed> $ids
     * @return array<int,int>
     */
    private static function userCounts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id >= 0)));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (User::withoutAllScopes('平台侧按租户统计账号数，必须跨租户')->withTrashed()->whereIn('tenant_id', $ids)->column('tenant_id') as $owner) {
            $id = (int)$owner;
            $out[$id] = ($out[$id] ?? 0) + 1;
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function pick(array $data): array
    {
        $picked = FilterInput::only($data, self::FIELDS);

        if (array_key_exists('expire_at', $picked)) {
            $picked['expire_at'] = self::normalizeExpire((string)$picked['expire_at']);
        }
        if (array_key_exists('status', $picked)) {
            $picked['status'] = (int)$picked['status'] === 1 ? 1 : 0;
        }
        foreach (['name', 'code'] as $key) {
            if (array_key_exists($key, $picked)) {
                $picked[$key] = mb_substr(trim((string)$picked[$key]), 0, 64);
            }
        }
        foreach (['contact' => 64, 'phone' => 32, 'remark' => 255] as $key => $len) {
            if (array_key_exists($key, $picked)) {
                $picked[$key] = mb_substr(trim((string)$picked[$key]), 0, $len);
            }
        }

        // 自定义域名：统一归一化后再入库；空串必须存 NULL
        // （唯一索引允许任意多个 NULL，但只允许一个空串，否则第二个租户就存不进去）
        if (array_key_exists('domain', $picked)) {
            $domain = TenantDomain::normalize((string)($picked['domain'] ?? ''));
            if ($domain !== '' && !self::isValidDomain($domain)) {
                throw new ApiException(I18n::t('tenant.domain_invalid'), 422);
            }
            $picked['domain'] = $domain === '' ? null : $domain;
        }

        return $picked;
    }

    /**
     * 到期时间：只给日期时按**当天 23:59:59** 收口，避免「选了今天却已过期」。
     * 空值 = 不过期（NULL）。
     */
    private static function normalizeExpire(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false
            ? null
            : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? date('Y-m-d 23:59:59', $time) : date('Y-m-d H:i:s', $time));
    }

    /**
     * @param array<string,mixed> $data     已白名单化的数据
     * @param bool                $isCreate 新增时 name / code 必填
     */
    private static function assertFields(array $data, bool $isCreate): void
    {
        if ($isCreate || array_key_exists('name', $data)) {
            if ((string)($data['name'] ?? '') === '') {
                throw new ApiException(I18n::t('tenant.name_required'), 422);
            }
        }

        if (!$isCreate && !array_key_exists('code', $data)) {
            return;
        }

        $code = (string)($data['code'] ?? '');
        if ($code === '') {
            throw new ApiException(I18n::t('tenant.code_required'), 422);
        }
        // 标识会出现在 URL / 前端 key / 运维脚本里，收窄到「字母数字下划线短横」
        if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/', $code)) {
            throw new ApiException(I18n::t('tenant.code_invalid'), 422);
        }
    }

    /**
     * 标识唯一性：`uk_code` 是**全局唯一索引**，且软删的租户仍占用标识
     * （同用户名 / 角色标识的口径），因此查重必须带 `withTrashed()`。
     */
    private static function assertUniqueCode(string $code, int $excludeId): void
    {
        if ($code === '') {
            return;
        }

        $q = Tenant::withTrashed()->where('code', $code);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }

        $exist = $q->find();
        if ($exist) {
            throw new ApiException(
                !empty($exist->delete_time)
                    ? I18n::t('tenant.code_in_trashed', ['code' => $code])
                    : I18n::t('tenant.code_exists'),
                422
            );
        }
    }

    /**
     * 域名唯一性：`uk_domain` 是全局唯一索引，软删的租户仍占用域名（与 `code` 同口径）。
     *
     * 空域名直接放行：库里存的是 NULL，唯一索引不约束 NULL。
     */
    private static function assertUniqueDomain(string $domain, int $excludeId): void
    {
        if ($domain === '') {
            return;
        }

        $q = Tenant::withTrashed()->where('domain', $domain);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }

        $exist = $q->find();
        if ($exist) {
            throw new ApiException(
                !empty($exist->delete_time)
                    ? I18n::t('tenant.domain_in_trashed', ['domain' => $domain])
                    : I18n::t('tenant.domain_exists', ['domain' => $domain]),
                422
            );
        }
    }

    /**
     * 域名格式校验：只接受主机名（字母 / 数字 / 连字符 / 点），不允许协议、端口、路径、通配符。
     *
     * 刻意放行无点的单段名（`localhost`）与 IP 字面量，方便本地联调：
     * 这两类在开发机上是真实会用到的绑定值。
     */
    private static function isValidDomain(string $domain): bool
    {
        return mb_strlen($domain) <= 128
            && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain) === 1;
    }
}
