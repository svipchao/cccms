<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\model\Tenant;
use Throwable;

/**
 * 租户自定义域名解析。
 *
 * ## 解决什么问题
 *
 * 多租户 SaaS 里，客户通常用自己的域名（如 `crm.customer.com`）访问系统，
 * 而不是共用主域名再手工选租户。绑定关系存在 `sys_tenant.domain`（唯一索引）。
 *
 * ## 与 `TenantContext` 的分工
 *
 * - 本类只回答「这个 Host 属于哪个租户」，是**入口识别**；
 * - `TenantContext` 回答「当前请求的租户是谁」，取自登录用户的令牌。
 *
 * 两者不是替代关系：域名命中只用来**收窄可选范围**，不直接决定身份 ——
 * 身份仍由令牌里的 `tid` 与账号归属校验（见 `AuthService::buildContext()`）。
 *
 * ## 缓存
 *
 * 进程内缓存 60 秒：域名 → 租户的变更频率极低，但每请求查库没有必要。
 * 代价是后台改完域名最多 60 秒生效；需要立刻生效时调 `flush()`。
 */
final class TenantDomain
{
    /** 解析结果缓存秒数 */
    private const CACHE_TTL = 60;

    /** @var array<string,array{id:?int,at:int}> */
    private static array $cache = [];

    /**
     * 域名归一化：小写、去协议、去端口、去末尾的点。
     *
     * 统一入库与查询两侧的口径，避免「入库写 `CRM.Example.com`、请求来 `crm.example.com`」匹配不上。
     */
    public static function normalize(string $host): string
    {
        $host = trim($host);

        if (str_contains($host, '://')) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host   = is_string($parsed) ? $parsed : '';
        }

        $host = strtolower($host);
        // 去掉端口。IPv6 字面量不在支持范围：这里存的是浏览器访问用的域名。
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return rtrim($host, '.');
    }

    /**
     * 域名 → 租户 ID；未绑定、租户停用或已删除返回 null。
     */
    public static function resolve(string $host): ?int
    {
        $host = self::normalize($host);
        if ($host === '') {
            return null;
        }

        $now    = time();
        $cached = self::$cache[$host] ?? null;
        if ($cached !== null && $now - $cached['at'] < self::CACHE_TTL) {
            return $cached['id'];
        }

        $id = self::lookup($host);

        self::$cache[$host] = ['id' => $id, 'at' => $now];

        return $id;
    }

    /**
     * 当前请求 Host 命中的租户。
     *
     * 无请求上下文（CLI / 定时任务）返回 null —— 它们没有「从哪个域名进来」的概念。
     */
    public static function fromRequest(): ?int
    {
        try {
            $host = (string)request()->host();
        } catch (Throwable) {
            return null;
        }

        return self::resolve($host);
    }

    /** 清空进程内缓存：后台保存域名后调用，避免最多 60 秒的生效延迟 */
    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function lookup(string $host): ?int
    {
        // Tenant 是平台级元数据（$tenantScope / $dataScope 均关闭），
        // 这里按域名反查不会受当前登录租户影响。
        $row = Tenant::where('domain', $host)->where('status', 1)->find();

        return $row === null ? null : (int)$row->id;
    }
}
