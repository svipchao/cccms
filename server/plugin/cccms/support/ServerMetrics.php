<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use think\facade\Db;
use Throwable;

/**
 * 服务器资源采集（F6）。
 *
 * ## 数据来源与降级
 *
 * 全部读 `/proc`（Linux）与 PDO/Redis 自身，**不依赖 shell 命令**（不用 `top` / `free` / `nproc`）：
 * 容器里经常没有这些工具，`exec` 还可能被 `disable_functions` 关掉。
 * 拿不到的项一律返回 `null`（而不是 0）—— 「未知」与「零」在告警语境里含义完全不同，
 * Windows 开发机上 CPU / 负载 / 进程数就是 `null`，页面显示「—」。
 *
 * ## CPU 为什么要采样两次
 *
 * `/proc/stat` 给的是**累计 jiffies**，单次快照算不出「当前占用率」。因此：
 *   - 给 Prometheus 的是**累计计数器**（`cccms_node_cpu_seconds_total`），由抓取端 `rate()` 求值 ——
 *     零额外成本，且多实例各自准确；
 *   - 给页面的是 `cpuPercent()`：自己隔 200ms 采两次算差值。只在**人工打开的看板**上调用，
 *     不放进 `/metrics` 的抓取路径（否则每次抓取都要阻塞 200ms）。
 *
 * 同理，`snapshot()` 里不含任何 `sleep`，可以被 `/metrics` 安全地高频调用。
 */
final class ServerMetrics
{
    /** /proc/stat 的时钟频率：Linux 用户态固定 100Hz（USER_HZ） */
    private const CLK_TCK = 100.0;

    /** CPU 采样间隔（微秒）：太短噪声大，太长拖慢看板 */
    private const CPU_SAMPLE_US = 200000;

    /**
     * 一次性快照（无阻塞）：OS / CPU 计数器 / 负载 / 系统内存 / 进程数 / 运行时长 / PHP / Redis / MySQL。
     *
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        return [
            'os'        => self::os(),
            'cpu'       => self::cpuCounters(),
            'load'      => self::load(),
            'memory'    => self::memory(),
            'processes' => self::processCount(),
            'uptime'    => self::uptime(),
            'php'       => self::phpInfo(),
            'redis'     => self::redisInfo(),
            'mysql'     => self::mysqlInfo(),
        ];
    }

    /**
     * 当前 CPU 使用率（%）：隔 200ms 采两次 `/proc/stat` 求差值。
     *
     * 非 Linux（Windows / macOS）返回 null。
     */
    public static function cpuPercent(): ?float
    {
        $first = self::cpuCounters();
        if ($first === null) {
            return null;
        }

        usleep(self::CPU_SAMPLE_US);

        $second = self::cpuCounters();
        if ($second === null) {
            return null;
        }

        $total = $second['total'] - $first['total'];
        $idle  = $second['idle'] - $first['idle'];
        if ($total <= 0) {
            return null;
        }

        return round(max(0.0, min(100.0, ($total - $idle) / $total * 100)), 2);
    }

    /** 逻辑核数（/proc/cpuinfo 的 processor 行数） */
    public static function cpuCount(): ?int
    {
        $info = @file_get_contents('/proc/cpuinfo');
        if ($info === false || $info === '') {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $info);

        return $count > 0 ? $count : null;
    }

    /**
     * `/proc/stat` 第一行 → 累计秒数。
     *
     * @return array{total:float,idle:float,modes:array<string,float>}|null
     */
    public static function cpuCounters(): ?array
    {
        $stat = @file_get_contents('/proc/stat');
        if ($stat === false || $stat === '') {
            return null;
        }

        $line = strtok($stat, "\n");
        if ($line === false || !str_starts_with((string)$line, 'cpu ')) {
            return null;
        }

        $parts = preg_split('/\s+/', trim((string)$line)) ?: [];
        array_shift($parts);   // 去掉 "cpu"

        $names = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
        $modes = [];
        $total = 0.0;
        foreach ($names as $index => $name) {
            $seconds = isset($parts[$index]) ? (float)$parts[$index] / self::CLK_TCK : 0.0;
            $modes[$name] = round($seconds, 2);
            $total += $seconds;
        }

        return [
            'total' => round($total, 2),
            // idle 含 iowait：等待 IO 的 CPU 并没有被计算任务占用，算「忙」会误报
            'idle'  => round($modes['idle'] + $modes['iowait'], 2),
            'modes' => $modes,
        ];
    }

    /** 1 / 5 / 15 分钟平均负载；不支持时 null（Windows） */
    public static function load(): ?array
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        return is_array($load) && count($load) >= 3
            ? array_map(static fn ($v): float => round((float)$v, 2), array_slice($load, 0, 3))
            : null;
    }

    /**
     * 系统内存（/proc/meminfo，字节）。
     *
     * `used = MemTotal - MemAvailable`：不用 `MemFree`，那会把 page cache 也算成「已用」，
     * 在 Linux 上永远接近 100%。
     *
     * @return array{total:int,available:int,used:int,percent:float}|null
     */
    public static function memory(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false || $raw === '') {
            return null;
        }

        $values = [];
        foreach (preg_split('/\n/', $raw) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m) === 1) {
                $values[$m[1]] = (int)$m[2] * 1024;
            }
        }

        $total     = (int)($values['MemTotal'] ?? 0);
        $available = (int)($values['MemAvailable'] ?? ($values['MemFree'] ?? 0));
        if ($total <= 0) {
            return null;
        }

        $used = max(0, $total - $available);

        return [
            'total'     => $total,
            'available' => $available,
            'used'      => $used,
            'percent'   => round($used / $total * 100, 2),
        ];
    }

    /** 进程数（/proc 下的纯数字目录）；非 Linux 返回 null */
    public static function processCount(): ?int
    {
        $entries = @scandir('/proc');
        if ($entries === false) {
            return null;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && ctype_digit($entry)) {
                $count++;
            }
        }

        return $count > 0 ? $count : null;
    }

    /** 系统运行时长（秒）；非 Linux 返回 null */
    public static function uptime(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        return (int)floor((float)explode(' ', trim($raw))[0]);
    }

    /** @return array<string,mixed> */
    public static function os(): array
    {
        return [
            'name'    => php_uname('s') . ' ' . php_uname('r'),
            'release' => self::distro(),
            'arch'    => php_uname('m'),
            'host'    => (string)@gethostname(),
        ];
    }

    /** @return array<string,mixed> */
    public static function phpInfo(): array
    {
        return [
            'version'      => PHP_VERSION,
            'sapi'         => PHP_SAPI,
            'memory_used'  => memory_get_usage(true),
            'memory_limit' => HealthProbe::parseBytes((string)ini_get('memory_limit')),
            'workers'      => self::workerCount(),
        ];
    }

    /**
     * Redis 运行信息（`INFO` 的子集）。
     *
     * 取不到时返回 null：Redis 不可用本身就是 `/metrics` 里 `cccms_up{component="redis"}` 的事，
     * 这里再报 0 会让人以为「Redis 好好的，只是没连接」。
     *
     * @return array<string,float|int>|null
     */
    public static function redisInfo(): ?array
    {
        try {
            $info = Redis::info();
        } catch (Throwable) {
            return null;
        }

        if (!is_array($info)) {
            return null;
        }

        // phpredis 各版本返回的键大小写不一致（有的直接透传 INFO 原文），统一小写再取值，
        // 否则整块会静默取到 0 —— 看起来像「Redis 空着」，实际只是键没对上
        $info = array_change_key_case($info, CASE_LOWER);

        $pick = static function (string $key) use ($info): ?float {
            if (!isset($info[$key]) || !is_numeric($info[$key])) {
                return null;
            }

            return (float)$info[$key];
        };

        return [
            'used_memory'          => (int)($pick('used_memory') ?? 0),
            'used_memory_peak'     => (int)($pick('used_memory_peak') ?? 0),
            'connected_clients'    => (int)($pick('connected_clients') ?? 0),
            'blocked_clients'      => (int)($pick('blocked_clients') ?? 0),
            'ops_per_second'       => (int)($pick('instantaneous_ops_per_sec') ?? 0),
            'keyspace_hits'        => (int)($pick('keyspace_hits') ?? 0),
            'keyspace_misses'      => (int)($pick('keyspace_misses') ?? 0),
            'evicted_keys'         => (int)($pick('evicted_keys') ?? 0),
            'rejected_connections' => (int)($pick('rejected_connections') ?? 0),
            'uptime_seconds'       => (int)($pick('uptime_in_seconds') ?? 0),
        ];
    }

    /**
     * MySQL 运行状态（`SHOW GLOBAL STATUS` / `SHOW VARIABLES` 的子集）。
     *
     * @return array<string,float|int|string>|null
     */
    public static function mysqlInfo(): ?array
    {
        try {
            $status = Db::query(
                'SHOW GLOBAL STATUS WHERE Variable_name IN '
                . "('Threads_connected','Threads_running','Uptime','Questions','Slow_queries',"
                . "'Aborted_connects','Innodb_buffer_pool_bytes_data')"
            );
            $variables = Db::query("SHOW VARIABLES WHERE Variable_name IN ('max_connections','version')");
        } catch (Throwable) {
            return null;
        }

        $map  = self::pairMap((array)$status);
        $vars = self::pairMap((array)$variables);

        $connected = (int)($map['threads_connected'] ?? 0);
        $max       = (int)($vars['max_connections'] ?? 0);

        return [
            'version'           => (string)($vars['version'] ?? ''),
            'threads_connected' => $connected,
            'threads_running'   => (int)($map['threads_running'] ?? 0),
            'max_connections'   => $max,
            // 连接池用满就再也连不上了，这是最有预警价值的比例
            'connection_usage'  => $max > 0 ? round($connected / $max * 100, 2) : 0.0,
            'uptime_seconds'    => (int)($map['uptime'] ?? 0),
            'questions'         => (int)($map['questions'] ?? 0),
            'slow_queries'      => (int)($map['slow_queries'] ?? 0),
            'aborted_connects'  => (int)($map['aborted_connects'] ?? 0),
            'buffer_pool_bytes' => (int)($map['innodb_buffer_pool_bytes_data'] ?? 0),
        ];
    }

    /**
     * `SHOW ... WHERE` 的结果统一成 `小写键 => 值`。
     *
     * 两列的顺序由 MySQL 决定（`Variable_name` / `Value`），按列名取更稳，
     * 取不到列名时按位置兜底（不同驱动返回的键大小写不一致）。
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<string,string>
     */
    private static function pairMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $values = array_values($row);
            $key    = (string)($row['Variable_name'] ?? $values[0] ?? '');
            $value  = (string)($row['Value'] ?? $values[1] ?? '');
            if ($key !== '') {
                $map[strtolower($key)] = $value;
            }
        }

        return $map;
    }

    /** 读发行版名（/etc/os-release）；拿不到就返回内核名 */
    private static function distro(): string
    {
        $raw = @file_get_contents('/etc/os-release');
        if ($raw !== false && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $raw, $m) === 1) {
            return $m[1];
        }

        return (string)php_uname('s');
    }

    /**
     * webman 的 worker 进程数（同机部署时≈CPU 核数×4）。
     *
     * 拿不到 `/proc` 或配置就读绑定失败时返回 0，由前端显示「—」。
     */
    private static function workerCount(): int
    {
        try {
            $process = config('server.process') ?: [];
            $count   = (int)($process['count'] ?? 0);
            if ($count > 0) {
                return $count;
            }
        } catch (Throwable) {
            // 配置读不到就退回 CPU 核数估算
        }

        return (int)(self::cpuCount() ?? 0);
    }
}
