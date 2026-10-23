<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\OperationLog;
use plugin\cccms\support\HealthProbe;
use plugin\cccms\support\Metrics;
use plugin\cccms\support\ServerMetrics;
use plugin\cccms\support\SysConfig;
use Throwable;

/**
 * 接口监控看板（F4）。
 *
 * ## 数据源与 /metrics 保持一致
 *
 * 看板**不引入第二套埋点**，读的就是 `Metrics` 派生自 `sys_log` 的那份数据：
 * 这样 Prometheus 抓到的数和页面上看到的数同源，不会出现「看板正常、告警在响」。
 *
 * ## 为什么页面要提示 record_read
 *
 * `sys_log` 默认只记写操作与登录（见 `OperationLog`），读接口不落库。
 * 不说明这一点，运维会把「请求量很少」误判成流量低 —— 实际只是没记。
 */
final class MonitorLogic
{
    /** 趋势图回看上限（一天）；再长请用日志页 / 外部 TSDB */
    private const TREND_MAX_MINUTES = 1440;

    /** 趋势图回看下限：太短会因窗口粒度（1 分钟）而只剩一两个点 */
    private const TREND_MIN_MINUTES = 10;

    /**
     * 顶部指标卡：窗口内的请求量 / 错误率 / 分位耗时 / 在线会话 / 依赖状态。
     *
     * @return array<string,mixed>
     */
    public static function overview(): array
    {
        // 不带服务器资源段：总览卡片用不到，且那一段要额外打两条 SHOW STATUS + 一次 Redis INFO
        $snapshot = Metrics::collect(false);
        $http = (array)($snapshot['requests'] ?? []);
        $paths = (array)($snapshot['paths'] ?? []);
        $total = (int)($http['total'] ?? 0);
        $errors = (int)($http['errors'] ?? 0);

        return [
            'window' => (int)($snapshot['window'] ?? Metrics::WINDOW_SECONDS),
            'scrape_at' => (int)($snapshot['scrape_at'] ?? time()),
            'requests' => $total,
            'errors' => $errors,
            // 百分比在这里算好，前端只负责展示（避免各端各算一套、四舍五入不一致）
            'error_rate' => $total > 0 ? round($errors / $total * 100, 2) : 0.0,
            'quantiles' => (array)($http['quantiles'] ?? []),
            'online' => (int)($snapshot['online'] ?? 0),
            'components' => [
                'mysql' => (int)($snapshot['mysql'] ?? 0),
                'redis' => (int)($snapshot['redis'] ?? 0),
            ],
            'paths_top' => array_slice((array)($paths['rows'] ?? []), 0, 5),
            'status_codes' => self::statusCodes(),
            // 读接口是否落库：false 时页面必须提示「请求量只含写操作」
            'record_read' => SysConfig::getBool('log.record_read', false),
        ];
    }

    /**
     * 服务器资源看板（F6）。
     *
     * 与 `/metrics` 同一数据源（`ServerMetrics`），区别只在于这里**现场采样 CPU 200ms**
     * 好让页面直接显示百分比 —— 采样不放进 `/metrics`，否则每次抓取都要阻塞这么久。
     *
     * `warn` 在后端算好：阈值判断只能有一处，前端各算一套必然出现「页面红了但告警没响」。
     *
     * @return array<string,mixed>
     */
    public static function server(): array
    {
        $node = ServerMetrics::snapshot();
        $disk = HealthProbe::diskStats();

        $diskUsed = ($disk['total'] !== null && $disk['free'] !== null && $disk['total'] > 0)
            ? round((1 - $disk['free'] / $disk['total']) * 100, 2)
            : null;

        $thresholds = [
            'cpu'         => max(1, SysConfig::getInt('monitor.cpu_threshold', 85)),
            'memory'      => max(1, SysConfig::getInt('monitor.memory_threshold', 90)),
            'disk'        => max(1, SysConfig::getInt('monitor.disk_threshold', 90)),
            'connections' => max(1, SysConfig::getInt('monitor.connections_threshold', 80)),
        ];

        $cpuPercent = ServerMetrics::cpuPercent();
        $memory     = (array)($node['memory'] ?? []);
        $mysql      = (array)($node['mysql'] ?? []);

        return [
            'time'       => date('Y-m-d H:i:s'),
            'os'         => (array)($node['os'] ?? []),
            'php'        => (array)($node['php'] ?? []),
            'cpu'        => [
                'percent' => $cpuPercent,
                'cores'   => ServerMetrics::cpuCount(),
                'load'    => $node['load'] ?? null,
            ],
            'memory'     => [
                'total'     => $memory['total'] ?? null,
                'available' => $memory['available'] ?? null,
                'used'      => $memory['used'] ?? null,
                'percent'   => $memory['percent'] ?? null,
            ],
            'disk'       => [
                'total'        => $disk['total'],
                'free'         => $disk['free'],
                'used_percent' => $diskUsed,
            ],
            'processes'  => $node['processes'] ?? null,
            'uptime'     => $node['uptime'] ?? null,
            'redis'      => $node['redis'] ?? null,
            'mysql'      => $mysql === [] ? null : $mysql,
            'thresholds' => $thresholds,
            'warn'       => [
                'cpu'         => $cpuPercent !== null && $cpuPercent >= $thresholds['cpu'],
                'memory'      => isset($memory['percent']) && (float)$memory['percent'] >= $thresholds['memory'],
                'disk'        => $diskUsed !== null && $diskUsed >= $thresholds['disk'],
                'connections' => (float)($mysql['connection_usage'] ?? 0) >= $thresholds['connections'],
            ],
        ];
    }

    /**
     * 按接口路径聚合（排序：请求量倒序，条数受 Metrics::PATH_LIMIT 约束）。
     *
     * @return array{window:int,rows:array<int,array>}
     */
    public static function paths(): array
    {
        $since = date('Y-m-d H:i:s', time() - Metrics::WINDOW_SECONDS);
        $stats = Metrics::pathStats($since);

        return [
            'window' => Metrics::WINDOW_SECONDS,
            'rows' => (array)($stats['rows'] ?? []),
        ];
    }

    /**
     * 每分钟一条的趋势（请求量 / 错误量 / 平均耗时）。
     *
     * 没有数据的分钟要补 0：否则折线图会把「中间空了三分钟」画成一条直线，
     * 看起来像是那段时间流量平稳，实际是压根没数据。
     *
     * @return array{minutes:int,labels:string[],requests:int[],errors:int[],avg_cost:float[]}
     */
    public static function trend(int $minutes = 60): array
    {
        $minutes = max(self::TREND_MIN_MINUTES, min(self::TREND_MAX_MINUTES, $minutes));
        $start = time() - ($minutes - 1) * 60;
        $since = date('Y-m-d H:i:s', $start);

        try {
            // 走模型而不是 Db::name：`sys_log` 是受控表，直查会绕过数据权限（data-scope-check R2）。
            // 监控是平台级看板，趋势必须看全量请求，因此显式跳出作用域并写明理由。
            $rows = OperationLog::withoutAllScopes('接口监控是平台级看板，趋势统计必须覆盖全量请求')
                ->field(
                    "DATE_FORMAT(create_time, '%Y-%m-%d %H:%i') AS bucket,"
                    . ' COUNT(*) AS total,'
                    . ' SUM(status_code >= 400) AS errors,'
                    . ' AVG(cost) AS avg_cost'
                )
                ->where('create_time', '>=', $since)
                ->group('bucket')
                ->order('bucket', 'asc')
                ->select()
                ->toArray();
        } catch (Throwable) {
            $rows = [];
        }

        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[(string)$row['bucket']] = $row;
        }

        $labels = [];
        $requests = [];
        $errors = [];
        $avgCost = [];
        for ($i = 0; $i < $minutes; $i++) {
            $bucket = date('Y-m-d H:i', $start + $i * 60);
            $row = $byBucket[$bucket] ?? null;
            // 只回传「时:分」，日期由前端按窗口长度决定是否展示
            $labels[] = substr($bucket, 11);
            $requests[] = (int)($row['total'] ?? 0);
            $errors[] = (int)($row['errors'] ?? 0);
            $avgCost[] = round((float)($row['avg_cost'] ?? 0), 2);
        }

        return [
            'minutes' => $minutes,
            'labels' => $labels,
            'requests' => $requests,
            'errors' => $errors,
            'avg_cost' => $avgCost,
        ];
    }

    /**
     * 窗口内状态码分布（按 2xx / 3xx / 4xx / 5xx 归组）。
     *
     * 用 `DIV 100` 而不是 CASE WHEN：一次分组就够，且能直接走索引条件。
     *
     * @return array<int,array{group:string,count:int}>
     */
    private static function statusCodes(): array
    {
        $since = date('Y-m-d H:i:s', time() - Metrics::WINDOW_SECONDS);
        try {
            $rows = OperationLog::withoutAllScopes('接口监控是平台级看板，状态码分布必须覆盖全量请求')
                ->field('status_code DIV 100 AS code_group, COUNT(*) AS total')
                ->where('create_time', '>=', $since)
                ->group('code_group')
                ->order('code_group', 'asc')
                ->select()
                ->toArray();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $group = (int)$row['code_group'];
            $out[] = [
                // 0xx / 1xx 不会出现；异常值统一归到 other，避免前端出现「0xx」这种怪标签
                'group' => in_array($group, [2, 3, 4, 5], true) ? $group . 'xx' : 'other',
                'count' => (int)$row['total'],
            ];
        }

        return $out;
    }
}
