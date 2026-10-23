<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * 租户上下文：当前请求属于哪个租户，落到查询与写入两侧。
 *
 * 租户是**硬边界**（超管也不例外，跨租户只能显式切换），数据档位是边界内
 * 再收窄的软范围 —— 所以租户条件必须由独立的模型作用域注入，不能放进
 * `DataScope::row()`（后者在「全部数据」档位会直接 return）。
 *
 * 有当前用户才生效；CLI / 登录 / 公共路由没有身份，按平台级处理。
 * 但**请求内**缺身份属于缺陷（而不是平台身份）：读操作返回空集并记
 * `runtime/logs/tenant-anomaly.log`，写操作返回 503。
 */
final class TenantContext
{
    /**
     * 平台/默认租户 ID。
     *
     * `sys_tenant` **不占用这一行**（MySQL 自增列插入 0 会被当成「取下一个自增值」），
     * 它是虚拟租户：所有老数据、平台自身的账号与组织都落在 0。
     */
    public const PLATFORM_ID = 0;

    /** 身份形态（见 `mode()`） */
    public const MODE_TENANT = 'tenant';
    public const MODE_PLATFORM = 'platform';
    public const MODE_ANONYMOUS = 'anonymous';

    /**
     * 执行期身份覆盖（仅 `runAs()` 使用）：`null` = 未覆盖。
     *
     * 与 `DataScope` 的请求级 `WeakMap` 不同，这里刻意用一个可恢复的静态值：
     * 定时任务「立即执行」跑在请求内，但身份必须换成**任务属主租户**，
     * 而 `request()->user` 是操作者。try/finally 保证执行完恢复，不泄漏给后续请求。
     */
    private static ?int $overrideTenantId = null;

    /**
     * 「当前是否处于 HTTP 请求内」的探测函数（`null` = 用 `request()` 助手）。
     *
     * 只给测试用：CLI 测试进程里没有请求上下文，无法覆盖「匿名请求 → 失败关闭」这条分支。
     *
     * @var (callable():bool)|null
     */
    private static $requestDetector = null;

    /**
     * 身份形态：`tenant`（租户账号）/ `platform`（平台或显式切换）/ `anonymous`（无身份）。
     *
     * `anonymous` 有两种合法来源：CLI（不在请求里）与登录 / 公共路由（在请求里但没鉴权）。
     * 两者对数据的影响不同 —— 见 `isAnonymousRequest()`。
     */
    public static function mode(): string
    {
        $tenantId = self::currentTenantId();

        if ($tenantId === null) {
            return self::MODE_ANONYMOUS;
        }

        return $tenantId === self::PLATFORM_ID ? self::MODE_PLATFORM : self::MODE_TENANT;
    }

    /**
     * 是否「在 HTTP 请求里，但没有租户身份」（P2-2）。
     *
     * CLI / 定时任务 / 命令不在请求里，平台级跨租户处理是合法的 → 返回 false；
     * 而**业务请求**里丢失租户身份只可能是缺陷：新加的 `#[NoLogin]` 公开接口没绑定租户、
     * 或中间件顺序被改动导致 `CheckLogin` 没跑。此时读操作必须返回空集、
     * 写操作必须拒绝 —— 否则就是「静默跨租户读全量」。
     *
     * 登录 / 找回密码链路同样命中本方法，但它们要么走 `withoutAllScopes()`（显式跨租户），
     * 要么只碰平台级表（`sys_menu` / `sys_config` / 关联表），因此不受影响。
     */
    public static function isAnonymousRequest(): bool
    {
        if (self::currentTenantId() !== null) {
            return false;
        }

        return self::inRequest();
    }

    /** @internal 仅供测试替换「当前是否在请求内」的判定 */
    public static function setRequestDetector(?callable $detector): void
    {
        self::$requestDetector = $detector;
    }

    private static function inRequest(): bool
    {
        if (self::$requestDetector !== null) {
            return (bool)(self::$requestDetector)();
        }

        $request = function_exists('request') ? request() : null;

        return $request !== null;
    }

    /** 当前请求所属租户；无用户上下文（CLI / 登录链路 / 公共路由）返回 null */
    public static function currentTenantId(): ?int
    {
        if (self::$overrideTenantId !== null) {
            return self::$overrideTenantId;
        }

        $user = DataScope::currentUser();

        return $user?->tenantId;
    }

    /**
     * 以指定租户身份执行一段代码（`TenantContext::PLATFORM_ID` = 平台）。
     *
     * 定时任务据此统一「手动 / 定时」两条执行路径的身份（P1-2）：
     * 同一个任务，谁点的「立即执行」不影响它看到与写入哪个租户的数据。
     */
    public static function runAs(int $tenantId, callable $fn): mixed
    {
        $previous = self::$overrideTenantId;
        self::$overrideTenantId = $tenantId;

        try {
            return $fn();
        } finally {
            self::$overrideTenantId = $previous;
        }
    }

    /**
     * 参与租户隔离的表（不含连接前缀），与「模型声明 `$tenantScope = true`」一一对应。
     *
     * 模型查询由 `BaseModel` 的全局作用域自动收敛，不需要这张名单；
     * 它只服务于**查询构造器直查**的入口（`DictLogic` / `CategoryLogic` / `RecycleLogic`
     * 等尚未模型化的模块），让那些路径也能拿到同一套租户条件。
     */
    public const TABLES = [
        'user', 'dept', 'role', 'post', 'data_rule', 'data_scope_table',
        'notice', 'file', 'category', 'dict_type', 'dict_data', 'crontab',
    ];

    /** 该表是否参与租户隔离（不含前缀的表名） */
    public static function participates(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    /**
     * 查询构造器直查入口：等价 `Db::name($table)`，额外按当前租户收敛。
     *
     * 未参与隔离的表（菜单 / 配置 / 日志 / 关联表 / 插件表）以及无用户上下文
     * （CLI / 登录链路）时原样返回，调用方不需要自己判断。
     */
    public static function table(string $table)
    {
        $query = Db::name($table);
        self::applyToQuery($query, $table);

        return $query;
    }

    /**
     * 查询侧：给**任意查询构造器**注入租户条件（`TenantContext::table()` 的底层实现）。
     *
     * @param mixed  $query 查询对象（Db::name / Db::table 的返回值）
     * @param string $table 不含前缀的表名
     */
    public static function applyToQuery($query, string $table): void
    {
        if (!self::participates($table)) {
            return;
        }

        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            // 业务请求里丢了租户身份（P2-2）：宁可不返回数据，也不能返回全量
            if (self::isAnonymousRequest()) {
                self::failClosed($query);
            }

            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    /**
     * 写入侧（查询构造器版）：剔除调用方传入的 `tenant_id` 并盖成当前租户。
     *
     * 模型写入由 `ScopedQuery::protect()` 兜住；直查 insert 没有这层保护，
     * 不盖租户会让新行落到 `tenant_id = 0`（默认值），创建完自己反而看不见。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function stamp(string $table, array $data): array
    {
        if (!self::participates($table)) {
            return $data;
        }

        unset($data['tenant_id']);

        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            // 直查写入没有模型层兜底：身份缺失时若继续写，行会落到 tenant_id = 0（平台），
            // 之后谁都「看不见」它，排查成本极高 —— 直接拒绝
            if (self::isAnonymousRequest()) {
                throw new ApiException(I18n::t('common.tenant_context_missing'), 503);
            }

            return $data;
        }

        $data['tenant_id'] = $tenantId;

        return $data;
    }

    /**
     * 是否处于平台租户（无用户上下文时同样按平台处理，供只读展示使用）。
     *
     * ⚠️ 无用户上下文（CLI / 登录链路 / 公共路由）时 `currentTenantId()` 返回 **null**，
     * 而 `null === 0` 是 false —— 直接比较会把「平台上下文」误判成「非平台」。
     * 这里显式把 null 归到平台，与本文档的契约一致。
     */
    public static function isPlatform(): bool
    {
        $tenantId = self::currentTenantId();

        return $tenantId === null || $tenantId === self::PLATFORM_ID;
    }

    /**
     * 租户是否可用：存在、未删除、已启用、未过期。平台租户恒可用。
     *
     * 每个已鉴权请求都会走一次（`AuthService::buildContext`）：租户被停用 / 到期后，
     * 该租户下所有账号**下一次请求即 401**，无需额外拉黑令牌 —— 与「禁用用户立即生效」
     * 是同一套口径。平台租户直接返回，不产生额外查询。
     */
    public static function usable(int $tenantId): bool
    {
        // 非法 id（负数）按平台处理：调用方传入未初始化的值时不应把整条鉴权链判成不可用
        if ($tenantId <= self::PLATFORM_ID) {
            return true;
        }

        try {
            $row = SoftDelete::apply(Db::name('tenant'))
                ->field(['status', 'expire_at'])
                ->where('id', $tenantId)
                ->find();
        } catch (Throwable) {
            // 表还没建（升级过程中）不让鉴权整体崩掉：按不可用处理更安全
            return false;
        }

        if (!$row || (int)$row['status'] !== 1) {
            return false;
        }

        $expire = (string)($row['expire_at'] ?? '');

        return $expire === '' || $expire > date('Y-m-d H:i:s');
    }

    /**
     * 查询侧：给模型查询注入租户条件。
     *
     * 由 `BaseModel::scopeTenant()` 调用，挂在全局作用域上，因此
     * 列表、详情、单条写操作（`where(id)->update()`）全部自动带上，
     * 不存在「列表过滤了、按 id 直调却越权」的漏网。
     *
     * @param mixed $query 模型查询对象
     */
    public static function applyToModelQuery($query, bool $participates): void
    {
        if (!$participates) {
            return;
        }

        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            // 同上：模型查询走的是同一条租户边界，缺身份时不能放行
            if (self::isAnonymousRequest()) {
                self::failClosed($query);
            }

            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    /**
     * 写入侧：剔除客户端传入的 `tenant_id`，并按当前租户强制写入。
     *
     * 由 `ScopedQuery::protect()` 在写库前统一调用，覆盖 insert / insertAll /
     * update / save 全部路径，业务层不需要（也不可能）自己填租户。
     *
     * 为什么**没有**「insert 才写、update 不写」的区分：
     * 更新语句已经被租户作用域收窄（跨租户的行匹配不到），所以把 tenant_id
     * 一并写入只是同值覆盖，不会把数据搬到别的租户；统一处理反而少一处分支。
     *
     * @param array<string,mixed> $data         引用传入，调用后 tenant_id 已是服务端认定值
     * @param bool                $tenantScoped 该查询是否仍挂着租户作用域。显式
     *                                          `withoutAllScopes()`（登录链路更新登录信息）
     *                                          属于有意跨租户，不应被「缺身份」拦下
     */
    public static function applyWriteRules(array &$data, bool $participates, bool $tenantScoped = true): void
    {
        if (!$participates) {
            return;
        }

        // 客户端不可控：先剔除，再按当前租户回填
        unset($data['tenant_id']);

        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            if ($tenantScoped && self::isAnonymousRequest()) {
                throw new ApiException(I18n::t('common.tenant_context_missing'), 503);
            }

            return;
        }

        $data['tenant_id'] = $tenantId;
    }

    /**
     * 读操作失败关闭：拼一个永假条件（与 `DataScope` 的自定义档无规则同口径）。
     *
     * 不用抛异常：列表 / 详情在「丢了身份」时返回空集比 500 更符合直觉，
     * 同时把事件写进 `runtime/logs/tenant-anomaly.log`，让它在运维侧可见。
     *
     * @param mixed $query
     */
    private static function failClosed($query): void
    {
        $query->whereRaw('1 = 0');
        self::logAnomaly();
    }

    /**
     * 记录一次「请求内缺租户身份」的异常。
     *
     * 每个请求最多记一条（挂在 request 上的动态属性），避免列表循环里刷屏；
     * 常驻进程下不能用静态变量做节流（会跨请求泄漏）。
     */
    private static function logAnomaly(): void
    {
        $request = function_exists('request') ? request() : null;
        if ($request === null || !empty($request->tenantAnomalyLogged)) {
            return;
        }

        $request->tenantAnomalyLogged = true;

        if (!function_exists('runtime_path')) {
            return;
        }

        @file_put_contents(
            runtime_path() . '/logs/tenant-anomaly.log',
            sprintf(
                "[%s] %s %s 无租户身份却访问隔离表（读操作已返回空集）\n",
                date('Y-m-d H:i:s'),
                $request->method(),
                $request->path()
            ),
            FILE_APPEND
        );
    }
}
