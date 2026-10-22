<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 列表分页参数的唯一入口。
 *
 * 此前每个 Logic 各写一遍 `max(1, (int)($params['page'] ?? 1))`，
 * 且 limit 没有上限 —— `?limit=1000000` 会让常驻进程一次查询就吃掉大量内存。
 */
final class PageInput
{
    /** 单页上限：超过按上限处理 */
    public const MAX_LIMIT = 200;

    /** 默认每页条数 */
    public const DEFAULT_LIMIT = 15;

    /**
     * @param array<string,mixed> $params
     * @return array{page:int,limit:int}
     */
    public static function of(array $params, int $defaultLimit = self::DEFAULT_LIMIT): array
    {
        $limit = (int)($params['limit'] ?? $defaultLimit);
        $limit = $limit > 0 ? min($limit, self::MAX_LIMIT) : $defaultLimit;

        return [
            'page'  => max(1, (int)($params['page'] ?? 1)),
            'limit' => $limit,
        ];
    }
}
