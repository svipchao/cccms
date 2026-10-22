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
     * 采集指标快照。
     *
     * @return array<string,mixed>
     */
    public static function collect(): array
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);

        return [
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
            'online'     => OnlineSession::count(),
            'crontab'    => self::crontabStats(),
            'crontab_heartbeat_age' => self::heartbeatAge(),
            'logchain'   => self::logchainStats(),
            'disk'       => HealthProbe::diskStats(),
            'memory'     => HealthProbe::memoryStats(),
        ];
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
