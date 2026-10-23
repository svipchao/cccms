<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use think\facade\Db;
use Throwable;

/**
 * Prometheus 指标（`GET /metrics`）。
 *
 * ## 为什么不使用进程内计数器
 *
 * Webman 是**多进程**常驻模型（`start.php` 起多个 worker），进程内计数器只能看到
 * 「当前 worker 自己处理过多少请求」，被 Prometheus 抓到哪个进程就报哪个进程的数，
 * 多实例部署时更是完全失真。因此这里**统一从可共享的来源派生**：
 *   - 请求量 / 错误量 / 耗时分位 → `sys_log` 的**滚动窗口**（`log.record_read` 打开时
 *     还包含读请求，默认只有写请求 + 登录）；
 *   - 在线会话数 → Redis 的会话索引 ZSet（`OnlineSession::count()`）；
 *   - 依赖连通性 → 与 `/healthz` 同一套探测；
 *   - 定时任务最近状态 → `sys_crontab_log` 的最新一条。
 *
 * 代价是指标精度受「日志保留策略」影响（日志被清理后窗口内数据会变少），
 * 换来的是**跨进程 / 跨实例口径一致**，这对告警来说比精度更重要。
 *
 * ## 可测性
 *
 * `collect()` 负责取数（有 IO），`render()` 是**纯函数**（快照 → 文本），
 * 因此格式化规则（转义、`# TYPE`、分位命名）可以单测，不需要真库真缓存。
 */
final class Metrics
{
    /** 滚动窗口：请求量 / 错误量 / 耗时分位都按它统计 */
    public const WINDOW_SECONDS = 300;

    /** 分位采样上限：只取窗口内**最近的** N 条再排序，避免大表把内存拉爆 */
    private const SAMPLE_LIMIT = 20000;

    /** 定时任务状态最多导出多少个任务（按最近执行倒序） */
    private const CRONTAB_LIMIT = 100;

    /**
     * path 维度最多导出多少条（按请求量倒序）。
     *
     * path 来自客户端 URL，是**不受控输入**：扫描器 / 探测路径 / 手输错误地址都会
     * 制造新取值。label 基数失控会让 Prometheus 侧内存爆炸，所以必须截断。
     */
    public const PATH_LIMIT = 20;

    /**
     * 采集指标快照。
     *
     * `$withNode = false` 供只要接口指标的调用方使用（看板总览 `MonitorLogic::overview()`）：
     * 服务器资源那一段要额外发两条 `SHOW ... STATUS` 与一次 `Redis::info()`，
     * 界面刷新频率远高于抓取，没必要每次都查。
     *
     * @return array<string,mixed>
     */
    public static function collect(bool $withNode = true): array
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);

        $snapshot = [
            'window'     => self::WINDOW_SECONDS,
            'scrape_at'  => time(),
            'mysql'      => self::componentUp(static fn () => Db::query('SELECT 1') !== null),
            'redis'      => self::componentUp(static function (): bool {
                $key = 'cccms:metrics:' . bin2hex(random_bytes(4));
                Redis::setex($key, 5, '1');
                $value = Redis::get($key);
                Redis::del($key);

                return $value === '1';
            }),
            'requests'   => self::httpStats($since),
            'paths'      => self::pathStats($since),
            'online'     => OnlineSession::count(),
            'crontab'    => self::crontabStats(),
            'crontab_heartbeat_age' => self::heartbeatAge(),
            'logchain'   => self::logchainStats(),
            'disk'       => HealthProbe::diskStats(),
            'memory'     => HealthProbe::memoryStats(),
        ];

        if ($withNode) {
            // F6：服务器资源（CPU 累计计数器 / 负载 / 系统内存 / 进程 / Redis / MySQL）
            $snapshot['node'] = ServerMetrics::snapshot();
        }

        return $snapshot;
    }

    /**
     * 渲染成 Prometheus 文本格式（`text/plain; version=0.0.4`）。
     *
     * @param array<string,mixed> $snapshot `collect()` 的返回值
     */
    public static function render(array $snapshot): string
    {
        $out = [];
        $add = static function (string $name, string $help, string $type, array $lines) use (&$out): void {
            $out[] = '# HELP ' . $name . ' ' . $help;
            $out[] = '# TYPE ' . $name . ' ' . $type;
            foreach ($lines as $line) {
                $out[] = $line;
            }
        };

        $window = (int)($snapshot['window'] ?? self::WINDOW_SECONDS);
        $windowLabel = 'window="' . $window . 's"';

        $add('cccms_up', '组件连通性：1 = 正常，0 = 不可用。', 'gauge', [
            'cccms_up{component="mysql"} ' . ((int)($snapshot['mysql'] ?? 0)),
            'cccms_up{component="redis"} ' . ((int)($snapshot['redis'] ?? 0)),
        ]);

        $http = (array)($snapshot['requests'] ?? []);
        $add('cccms_http_requests_total', '滚动窗口内的请求数（来源：sys_log）。', 'gauge', [
            'cccms_http_requests_total{' . $windowLabel . '} ' . (int)($http['total'] ?? 0),
        ]);
        $add('cccms_http_errors_total', '滚动窗口内状态码 >= 400 的响应数。', 'gauge', [
            'cccms_http_errors_total{' . $windowLabel . '} ' . (int)($http['errors'] ?? 0),
        ]);

        $duration = [];
        foreach ((array)($http['quantiles'] ?? []) as $quantile => $seconds) {
            $duration[] = sprintf(
                'cccms_http_request_duration_seconds{quantile="%s"} %s',
                (string)$quantile,
                self::number((float)$seconds)
            );
        }
        $add(
            'cccms_http_request_duration_seconds',
            '滚动窗口内请求耗时（秒，来源：sys_log.cost）。',
            'gauge',
            $duration === [] ? [] : $duration
        );

        // path 维度：每行都带 path 标签，条数受 PATH_LIMIT 约束（见 normalizePath 注释）
        $paths = (array)($snapshot['paths'] ?? []);
        $add(
            'cccms_http_path_requests_total',
            '滚动窗口内按路径聚合的请求数（来源：sys_log，Top ' . self::PATH_LIMIT . '）。',
            'gauge',
            (array)($paths['requests'] ?? [])
        );
        $add(
            'cccms_http_path_errors_total',
            '滚动窗口内按路径聚合的状态码 >= 400 响应数。',
            'gauge',
            (array)($paths['errors'] ?? [])
        );
        $add(
            'cccms_http_path_duration_seconds',
            '滚动窗口内按路径聚合的 P95 耗时（秒，来源：sys_log.cost 采样）。',
            'gauge',
            (array)($paths['duration'] ?? [])
        );

        $add('cccms_online_sessions', '当前在线会话数（Redis 会话索引）。', 'gauge', [
            'cccms_online_sessions ' . (int)($snapshot['online'] ?? 0),
        ]);

        $crontab = (array)($snapshot['crontab'] ?? []);
        $add(
            'cccms_crontab_last_run_status',
            '定时任务最近一次执行状态：1 成功 / 0 失败 / 2 跳过 / 3 超时释放。',
            'gauge',
            (array)($crontab['status_lines'] ?? [])
        );
        $add(
            'cccms_crontab_last_run_timestamp_seconds',
            '定时任务最近一次执行时间（Unix 秒）。',
            'gauge',
            (array)($crontab['time_lines'] ?? [])
        );

        $disk  = (array)($snapshot['disk'] ?? []);
        $diskFree = $disk['free'] ?? null;
        $diskTotal = $disk['total'] ?? null;
        $add('cccms_disk_free_bytes', '运行时目录所在分区的剩余空间（字节）。', 'gauge', is_numeric($diskFree) ? [
            'cccms_disk_free_bytes ' . self::number((float)$diskFree),
        ] : []);
        $add('cccms_disk_total_bytes', '运行时目录所在分区的总空间（字节）。', 'gauge', is_numeric($diskTotal) ? [
            'cccms_disk_total_bytes ' . self::number((float)$diskTotal),
        ] : []);

        $memory = (array)($snapshot['memory'] ?? []);
        $add('cccms_memory_usage_bytes', '当前进程占用内存（字节，memory_get_usage(true)）。', 'gauge', [
            'cccms_memory_usage_bytes ' . (int)($memory['used'] ?? 0),
        ]);
        $limit = (int)($memory['limit'] ?? 0);
        $add(
            'cccms_memory_limit_bytes',
            'php.ini memory_limit（字节）；0 表示未限制。',
            'gauge',
            ['cccms_memory_limit_bytes ' . $limit]
        );

        $add(
            'cccms_crontab_heartbeat_age_seconds',
            '调度进程心跳距今秒数；-1 表示取不到（进程未启动或 Redis 不可用）。',
            'gauge',
            ['cccms_crontab_heartbeat_age_seconds ' . (int)($snapshot['crontab_heartbeat_age'] ?? -1)]
        );

        // ---- 服务器资源（F6）----
        // CPU 导出的是**累计计数器**：抓取端用 rate() 求占用率，服务端不必阻塞采样。
        $node = (array)($snapshot['node'] ?? []);
        $cpu  = $node['cpu'] ?? null;
        $cpuLines = [];
        if (is_array($cpu)) {
            foreach ((array)($cpu['modes'] ?? []) as $mode => $seconds) {
                $cpuLines[] = 'cccms_node_cpu_seconds_total{mode="'
                    . self::escapeLabel((string)$mode) . '"} ' . self::number((float)$seconds);
            }
        }
        $add('cccms_node_cpu_seconds_total', 'CPU 各模式累计时间（秒，来源 /proc/stat）。', 'counter', $cpuLines);

        $cores = ServerMetrics::cpuCount();
        $add('cccms_node_cpu_count', '逻辑 CPU 核数（/proc/cpuinfo）。', 'gauge', $cores === null ? [] : [
            'cccms_node_cpu_count ' . $cores,
        ]);

        $load = $node['load'] ?? null;
        $loadLines = [];
        if (is_array($load)) {
            foreach (['1', '5', '15'] as $index => $period) {
                if (isset($load[$index])) {
                    $loadLines[] = 'cccms_node_load{period="' . $period . '"} ' . self::number((float)$load[$index]);
                }
            }
        }
        $add('cccms_node_load', '系统平均负载（1 / 5 / 15 分钟）。', 'gauge', $loadLines);

        $nodeMemory = (array)($node['memory'] ?? []);
        $add('cccms_node_memory_total_bytes', '系统内存总量（字节，/proc/meminfo）。', 'gauge', is_numeric($nodeMemory['total'] ?? null) ? [
            'cccms_node_memory_total_bytes ' . self::number((float)$nodeMemory['total']),
        ] : []);
        $add('cccms_node_memory_available_bytes', '系统可用内存（MemAvailable）。', 'gauge', is_numeric($nodeMemory['available'] ?? null) ? [
            'cccms_node_memory_available_bytes ' . self::number((float)$nodeMemory['available']),
        ] : []);
        $add('cccms_node_memory_used_percent', '系统内存使用率（%）。', 'gauge', is_numeric($nodeMemory['percent'] ?? null) ? [
            'cccms_node_memory_used_percent ' . self::number((float)$nodeMemory['percent']),
        ] : []);

        $processes = $node['processes'] ?? null;
        $add('cccms_node_processes', '系统进程数（/proc 数字目录计数）。', 'gauge', $processes === null ? [] : [
            'cccms_node_processes ' . (int)$processes,
        ]);

        $uptime = $node['uptime'] ?? null;
        $add('cccms_node_uptime_seconds', '系统运行时长（秒）。', 'gauge', $uptime === null ? [] : [
            'cccms_node_uptime_seconds ' . (int)$uptime,
        ]);

        $redisInfo = (array)($node['redis'] ?? []);
        $add('cccms_redis_used_memory_bytes', 'Redis 已用内存（字节）。', 'gauge', isset($redisInfo['used_memory']) ? [
            'cccms_redis_used_memory_bytes ' . (int)$redisInfo['used_memory'],
        ] : []);
        $add('cccms_redis_connected_clients', 'Redis 当前连接数。', 'gauge', isset($redisInfo['connected_clients']) ? [
            'cccms_redis_connected_clients ' . (int)$redisInfo['connected_clients'],
        ] : []);
        $add('cccms_redis_ops_per_second', 'Redis 每秒操作数（instantaneous_ops_per_sec）。', 'gauge', isset($redisInfo['ops_per_second']) ? [
            'cccms_redis_ops_per_second ' . (int)$redisInfo['ops_per_second'],
        ] : []);
        $add('cccms_redis_rejected_connections_total', 'Redis 因 maxclients 拒绝的连接数。', 'counter', isset($redisInfo['rejected_connections']) ? [
            'cccms_redis_rejected_connections_total ' . (int)$redisInfo['rejected_connections'],
        ] : []);

        $mysqlInfo = (array)($node['mysql'] ?? []);
        $add('cccms_mysql_threads_connected', 'MySQL 当前连接数。', 'gauge', isset($mysqlInfo['threads_connected']) ? [
            'cccms_mysql_threads_connected ' . (int)$mysqlInfo['threads_connected'],
        ] : []);
        $add('cccms_mysql_threads_running', 'MySQL 正在执行查询的连接数。', 'gauge', isset($mysqlInfo['threads_running']) ? [
            'cccms_mysql_threads_running ' . (int)$mysqlInfo['threads_running'],
        ] : []);
        $add('cccms_mysql_connection_usage_percent', 'MySQL 连接数占 max_connections 的比例（%）。', 'gauge', isset($mysqlInfo['connection_usage']) ? [
            'cccms_mysql_connection_usage_percent ' . self::number((float)$mysqlInfo['connection_usage']),
        ] : []);
        $add('cccms_mysql_uptime_seconds', 'MySQL 运行时长（秒）。', 'gauge', isset($mysqlInfo['uptime_seconds']) ? [
            'cccms_mysql_uptime_seconds ' . (int)$mysqlInfo['uptime_seconds'],
        ] : []);
        $add('cccms_mysql_questions_total', 'MySQL 已处理的语句总数。', 'counter', isset($mysqlInfo['questions']) ? [
            'cccms_mysql_questions_total ' . (int)$mysqlInfo['questions'],
        ] : []);
        $add('cccms_mysql_slow_queries_total', 'MySQL 慢查询累计数。', 'counter', isset($mysqlInfo['slow_queries']) ? [
            'cccms_mysql_slow_queries_total ' . (int)$mysqlInfo['slow_queries'],
        ] : []);

        $logchain = (array)($snapshot['logchain'] ?? []);
        $add(
            'cccms_logchain_degraded_total',
            '写入时未能进链的日志条数（签名密钥缺失 / 链表不可用）；-1 表示取值失败。',
            'gauge',
            ['cccms_logchain_degraded_total ' . (int)($logchain['degraded'] ?? -1)]
        );

        $add('cccms_metrics_scrape_timestamp_seconds', '本次抓取时间（Unix 秒）。', 'gauge', [
            'cccms_metrics_scrape_timestamp_seconds ' . (int)($snapshot['scrape_at'] ?? time()),
        ]);

        return implode("\n", $out) . "\n";
    }

    /**
     * 组件连通性探测。
     *
     * 探针抛错即视为不可用；**探测自身的问题不该让整个 `/metrics` 失败**，
     * 所以这里吞掉异常并返回 0（Prometheus 侧会看到 `cccms_up 0` 并触发告警）。
     *
     * @param callable():bool $probe
     */
    private static function componentUp(callable $probe): int
    {
        try {
            return $probe() ? 1 : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 审计链降级次数。
     *
     * 「密钥缺失 / 链表不可用 → 降级普通插入」是有意取舍，但降级必须可见。
     * 取不到时返回 **-1** 而不是 0：0 会被读成「一切正常」，-1 至少能让看板显示异常。
     *
     * @return array{degraded:int}
     */
    private static function logchainStats(): array
    {
        try {
            $value = Redis::get(LogChain::DEGRADED_KEY);
        } catch (Throwable) {
            return ['degraded' => -1];
        }

        return ['degraded' => ($value === null || $value === false) ? 0 : (int)$value];
    }

    /**
     * 调度进程心跳年龄（秒）。
     *
     * 与 `cccms_crontab_last_run_timestamp_seconds` 的分工：
     * 后者取自「最近一次任务执行」，任务全是每小时一次时天然很旧，
     * 无法区分「进程死了」与「还没到点」；心跳年龄才是进程存活证据。
     * 取不到返回 **-1**（未知），不能当 0（= 刚刚还活着）。
     */
    private static function heartbeatAge(): int
    {
        try {
            $value = Redis::get(CrontabRunner::HEARTBEAT_KEY);
        } catch (Throwable) {
            return -1;
        }

        if ($value === null || $value === false || $value === '') {
            return -1;
        }

        return max(0, time() - (int)$value);
    }

    /**
     * 窗口内的请求量 / 错误量与耗时分位。
     *
     * 分位取「窗口内最近 `SAMPLE_LIMIT` 条」再在 PHP 里排序：按 `cost` 排序取前 N 条
     * 会得到「最便宜的那批」而不是随机样本，分位数会系统性偏低。
     *
     * @return array{total:int,errors:int,quantiles:array<string,float>}
     */
    private static function httpStats(string $since): array
    {
        try {
            $total  = (int)Db::name('log')->where('create_time', '>=', $since)->count();
            $errors = (int)Db::name('log')->where('create_time', '>=', $since)->where('status_code', '>=', 400)->count();

            $costs = Db::name('log')
                ->where('create_time', '>=', $since)
                ->order('id', 'desc')
                ->limit(self::SAMPLE_LIMIT)
                ->column('cost');
        } catch (Throwable) {
            return ['total' => 0, 'errors' => 0, 'quantiles' => []];
        }

        $costs = array_map('intval', (array)$costs);
        sort($costs);

        $quantiles = [];
        foreach ([0.5, 0.95, 0.99] as $q) {
            $quantiles[(string)$q] = self::quantile($costs, $q) / 1000;   // ms → 秒
        }

        return ['total' => $total, 'errors' => $errors, 'quantiles' => $quantiles];
    }

    /**
     * 窗口内按接口路径聚合：请求数 / 错误数 / 平均耗时 / P95。
     *
     * 两次查询各司其职，**都不做 N+1**：
     *   ① 精确计数走 `GROUP BY path`（`idx_create_time` 覆盖窗口条件）；
     *   ② P95 需要窗口内每条的 cost，复用「最近 SAMPLE_LIMIT 条」再在 PHP 里分组排序，
     *      而不是对每个 path 分别查一次。
     *
     * 返回结构同时服务于 `/metrics`（`lines`）与后台看板（`rows`）。
     *
     * @return array{lines:array{requests:string[],errors:string[],duration:string[]},rows:array<int,array>}
     */
    public static function pathStats(string $since): array
    {
        $empty = ['lines' => ['requests' => [], 'errors' => [], 'duration' => []], 'rows' => []];

        try {
            $aggregated = Db::name('log')
                ->field('path, COUNT(*) AS total, SUM(status_code >= 400) AS errors, AVG(cost) AS avg_cost')
                ->where('create_time', '>=', $since)
                ->group('path')
                ->select()
                ->toArray();
        } catch (Throwable) {
            return $empty;
        }

        // 原始 path 先归一化再归并：/user/123 与 /user/456 必须是同一个 label
        $buckets = [];
        foreach ($aggregated as $row) {
            $path = self::normalizePath((string)($row['path'] ?? ''));
            $bucket = $buckets[$path] ?? ['path' => $path, 'total' => 0, 'errors' => 0, 'cost_sum' => 0.0];
            $bucket['total'] += (int)($row['total'] ?? 0);
            $bucket['errors'] += (int)($row['errors'] ?? 0);
            $bucket['cost_sum'] += (float)($row['avg_cost'] ?? 0) * (int)($row['total'] ?? 0);
            $buckets[$path] = $bucket;
        }

        uasort($buckets, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $buckets = array_slice($buckets, 0, self::PATH_LIMIT, true);

        $samples = self::pathCostSamples($since);

        $lines = ['requests' => [], 'errors' => [], 'duration' => []];
        $rows = [];
        foreach ($buckets as $path => $bucket) {
            $labels = '{path="' . self::escapeLabel($path) . '",window="' . self::WINDOW_SECONDS . 's"}';
            $lines['requests'][] = 'cccms_http_path_requests_total' . $labels . ' ' . $bucket['total'];
            $lines['errors'][] = 'cccms_http_path_errors_total' . $labels . ' ' . $bucket['errors'];
            $lines['duration'][] = 'cccms_http_path_duration_seconds{path="'
                . self::escapeLabel($path) . '",quantile="0.95"} '
                . self::number(self::quantile($samples[$path] ?? [], 0.95) / 1000);

            $rows[] = [
                'path' => $path,
                'total' => $bucket['total'],
                'errors' => $bucket['errors'],
                'avg_cost' => $bucket['total'] > 0 ? round($bucket['cost_sum'] / $bucket['total'], 2) : 0.0,
                'p95' => round(self::quantile($samples[$path] ?? [], 0.95), 2),
            ];
        }

        return ['lines' => $lines, 'rows' => $rows];
    }

    /**
     * 采样窗口内最近的 N 条 `(path, cost)`，按归一化 path 分组并升序。
     *
     * @return array<string,int[]>
     */
    private static function pathCostSamples(string $since): array
    {
        try {
            $rows = Db::name('log')
                ->field('path, cost')
                ->where('create_time', '>=', $since)
                ->order('id', 'desc')
                ->limit(self::SAMPLE_LIMIT)
                ->select()
                ->toArray();
        } catch (Throwable) {
            return [];
        }

        $samples = [];
        foreach ($rows as $row) {
            $samples[self::normalizePath((string)($row['path'] ?? ''))][] = (int)($row['cost'] ?? 0);
        }
        foreach ($samples as &$costs) {
            sort($costs);
        }

        return $samples;
    }

    /**
     * 路径归一化：把「具体值」压成占位符，避免 label 基数失控。
     *
     * 本项目路由没有 `{param}`（一律 query / body 传参），所以出现数字段基本只有两类：
     * ① 扫描器 / 手输的 404 路径；② 未来若加了 RESTful 路径。两者都该合并。
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '(unknown)';
        }
        // 每个模式都用 `(?=/|$)` 锚定**整段**：否则 `/9f8e7d...` 会被 `/9` 先命中，
        // 结果是 `/{n}f8e7d...`，label 反而更碎。
        $path = preg_replace('#/\d+(?=/|$)#', '/{n}', $path) ?? $path;
        $path = preg_replace(
            '#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i',
            '/{uuid}',
            $path
        ) ?? $path;
        $path = preg_replace('#/[0-9a-f]{16,}(?=/|$)#i', '/{id}', $path) ?? $path;

        return mb_strlen($path) > 80 ? mb_substr($path, 0, 80) . '…' : $path;
    }

    /**
     * 最近一次执行状态（按任务去重）。
     *
     * 取最近 `CRONTAB_LIMIT` 条日志后按 `crontab_id` 去重保序，
     * 这样每个任务只出现一次、且是它的最新一条。
     *
     * @return array{status_lines:string[],time_lines:string[]}
     */
    private static function crontabStats(): array
    {
        try {
            $rows = Db::name('crontab_log')
                ->field(['crontab_id', 'name', 'status', 'run_time'])
                ->order('id', 'desc')
                ->limit(self::CRONTAB_LIMIT)
                ->select()
                ->toArray();
        } catch (Throwable) {
            return ['status_lines' => [], 'time_lines' => []];
        }

        $seen         = [];
        $statusLines  = [];
        $timeLines    = [];

        foreach ($rows as $row) {
            $id = (int)$row['crontab_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $labels = sprintf(
                '{crontab_id="%d",name="%s"}',
                $id,
                self::escapeLabel((string)$row['name'])
            );

            $statusLines[] = 'cccms_crontab_last_run_status' . $labels . ' ' . (int)$row['status'];
            $timestamp     = $row['run_time'] ? (int)strtotime((string)$row['run_time']) ?: 0 : 0;
            $timeLines[]   = 'cccms_crontab_last_run_timestamp_seconds' . $labels . ' ' . $timestamp;
        }

        return ['status_lines' => $statusLines, 'time_lines' => $timeLines];
    }

    /**
     * 最近秩法取分位数（不插值）。
     *
     * 样本为空返回 0；`$q` 落在 `[0,1]`。
     *
     * @param int[] $sorted 已升序排列的样本
     */
    public static function quantile(array $sorted, float $q): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int)ceil($q * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return (float)$sorted[$index];
    }

    /** Prometheus 标签值转义：反斜杠 / 双引号 / 换行必须转义，否则抓取端解析出错 */
    public static function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /** 数值渲染：整数字节不写小数点，浮点保留 6 位有效小数并去掉尾随 0 */
    private static function number(float $value): string
    {
        if (floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (string)(int)$value;
        }

        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }
}
