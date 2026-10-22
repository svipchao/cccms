<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use InvalidArgumentException;

/**
 * 运行环境与「危险功能」的唯一判定入口（P2-8）。
 *
 * 危险功能 = 会写代码 / 覆盖项目文件 / 改库结构的后台能力。放行需同时满足：
 *   1. 仓库根存在 `frontend/package.json`（完整源码仓库，而非线上构建产物）；
 *   2. `plugin.cccms.{feature}.enable` 已打开（默认关闭）。
 *
 * 清单集中在 `DANGEROUS`：新增危险功能只改这里，门禁自然一致；未登记的功能名
 * 直接抛异常，不会静默放行。`isDevLayout()` 只缓存「文件是否存在」这一进程级事实，
 * 带 TTL，不涉及请求数据。
 */
final class Environment
{
    /** 开发态标记：仓库根必须存在该文件才认为是「完整源码仓库」 */
    public const DEV_MARKER = 'frontend/package.json';

    /** 自检结果缓存秒数：兼顾「删掉标记后能很快生效」与「不必每个请求 stat」 */
    private const DEV_CHECK_TTL = 60;

    /**
     * 危险功能注册表：`配置键 => 说明`（配置键为 `plugin.cccms.{key}.enable`）。
     *
     * @var array<string,string>
     */
    public const DANGEROUS = [
        'upgrade'   => '在线升级（拉取上游代码覆盖本地文件）',
        'generator' => '代码生成器（直接写入 Model / Logic / Controller / 路由 / 前端页面）',
    ];

    private static bool $devLayout = false;

    private static int $devLayoutCheckedAt = 0;

    /**
     * 仓库根（server 的上一级）。
     *
     * 注意不是 `base_path()`：那是 server 目录，而 `DEV_MARKER` 相对仓库根。
     * CLI 与 HTTP 走同一条路径，避免两种入口判出不同结果。
     */
    public static function root(): string
    {
        $server = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);

        return rtrim(str_replace('\\', '/', dirname($server)), '/');
    }

    /** 是否本地开发布局（带进程内缓存） */
    public static function isDevLayout(): bool
    {
        $now = time();
        if (self::$devLayoutCheckedAt !== 0 && $now - self::$devLayoutCheckedAt < self::DEV_CHECK_TTL) {
            return self::$devLayout;
        }

        clearstatcache();
        self::$devLayout          = is_file(self::root() . '/' . self::DEV_MARKER);
        self::$devLayoutCheckedAt = $now;

        return self::$devLayout;
    }

    /**
     * 危险功能是否可用：开发布局 **且** 该功能开关已打开。
     *
     * @throws InvalidArgumentException 功能未在 `DANGEROUS` 登记（防止「新功能忘了接入门禁」）
     */
    public static function featureEnabled(string $feature): bool
    {
        self::assertRegistered($feature);

        if (!self::isDevLayout()) {
            return false;
        }

        return (bool)config("plugin.cccms.{$feature}.enable", false);
    }

    /**
     * 被哪道闸挡住（'' = 未被挡）。页面提示需要区分「怎么打开」与「线上禁止」。
     *
     * @return ''|'environment'|'switch'
     */
    public static function blockedBy(string $feature): string
    {
        self::assertRegistered($feature);

        if (!self::isDevLayout()) {
            return 'environment';
        }

        return self::featureEnabled($feature) ? '' : 'switch';
    }

    private static function assertRegistered(string $feature): void
    {
        if (!isset(self::DANGEROUS[$feature])) {
            throw new InvalidArgumentException(
                "危险功能 {$feature} 未在 Environment::DANGEROUS 登记，不能使用统一门禁"
            );
        }
    }
}
