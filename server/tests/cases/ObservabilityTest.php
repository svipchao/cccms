<?php

declare(strict_types=1);

use plugin\cccms\support\EndpointAccess;
use plugin\cccms\support\HealthProbe;
use plugin\cccms\support\Metrics;

/**
 * P2-12：`/healthz` 探针与 `/metrics` 指标。
 *
 * 重点是**纯函数部分**（Prometheus 文本格式容易在细节上出错，而抓取端解析失败时
 * 表现为「指标整段消失」，很难从现象反推原因）：
 *   - `# HELP` / `# TYPE` 顺序、指标名与类型；
 *   - 标签值转义（反斜杠 / 双引号 / 换行）；
 *   - 数值格式（整数字节不写小数点）；
 *   - 分位数算法；
 *   - `php.ini` 容量写法解析。
 * `collect()` 依赖真实库与缓存，单独一条用例覆盖其结构。
 */
return static function (): void {
    suite('可观测端点（P2-12）');

    test('Prometheus 文本：每行都是合法的指标行或注释行', function (): void {
        $text = Metrics::render([
            'window'    => 300,
            'scrape_at' => 1_800_000_000,
            'mysql'     => 1,
            'redis'     => 0,
            'requests'  => ['total' => 120, 'errors' => 3, 'quantiles' => ['0.5' => 0.012, '0.95' => 0.08]],
            'online'    => 7,
            'crontab'   => [
                'status_lines' => ['cccms_crontab_last_run_status{crontab_id="1",name="日志清理"} 1'],
                'time_lines'   => ['cccms_crontab_last_run_timestamp_seconds{crontab_id="1",name="日志清理"} 1800000000'],
            ],
            'disk'      => ['free' => 1073741824.0, 'total' => 21474836480.0],
            'memory'    => ['used' => 2097152, 'limit' => 268435456],
        ]);

        ok(str_ends_with($text, "\n"), '文本必须以换行结尾（抓取端按行解析）');

        $helpSeen = [];
        foreach (explode("\n", trim($text)) as $line) {
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '# HELP ')) {
                $helpSeen[] = explode(' ', $line)[2];
                continue;
            }
            if (str_starts_with($line, '# TYPE ')) {
                continue;
            }
            ok(
                preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{.*\})? -?[0-9]+(\.[0-9]+)?$/', $line) === 1,
                "非法指标行：{$line}"
            );
        }

        // 每个声明的指标都必须同时有 HELP 与 TYPE
        foreach (['cccms_up', 'cccms_http_requests_total', 'cccms_online_sessions', 'cccms_disk_free_bytes'] as $name) {
            ok(in_array($name, $helpSeen, true), "缺少 # HELP {$name}");
        }
    });

    test('每个 # HELP 后紧跟同名 # TYPE', function (): void {
        $lines = explode("\n", trim(Metrics::render([
            'requests' => ['total' => 1, 'errors' => 0, 'quantiles' => ['0.5' => 0.1]],
            'crontab'  => ['status_lines' => [], 'time_lines' => []],
        ])));

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (!str_starts_with($lines[$i], '# HELP ')) {
                continue;
            }
            $name = explode(' ', $lines[$i])[2];
            // 类型不预设：CPU 等累计量是 counter，断言写死 gauge 会把新增指标误判成错误
            ok(
                isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '# TYPE ' . $name . ' '),
                "# HELP {$name} 之后必须紧跟同名 # TYPE"
            );
        }
    });

    test('标签值转义：反斜杠 / 双引号 / 换行', function (): void {
        same('a\\\\b', Metrics::escapeLabel('a\\b'), '反斜杠要转义');
        same('a\\"b', Metrics::escapeLabel('a"b'), '双引号要转义');
        same('a\\nb', Metrics::escapeLabel("a\nb"), '换行要转义为字面 \\n');
        same('正常名称', Metrics::escapeLabel('正常名称'), '普通文本原样保留');
    });

    test('数值格式：整数字节不写小数点，浮点去掉尾随 0', function (): void {
        $text = Metrics::render([
            'disk'     => ['free' => 1073741824.0, 'total' => 21474836480.0],
            'memory'   => ['used' => 1048576, 'limit' => 0],
            'requests' => ['total' => 5, 'errors' => 0, 'quantiles' => ['0.95' => 0.08, '0.99' => 1.0]],
        ]);

        contains('cccms_disk_free_bytes 1073741824', $text, '整数字节不该写成 1073741824.000000');
        contains('cccms_http_request_duration_seconds{quantile="0.95"} 0.08', $text, '浮点应去掉尾随 0');
        contains('cccms_http_request_duration_seconds{quantile="0.99"} 1', $text, '整数秒不该写成 1.000000');
        contains('cccms_memory_limit_bytes 0', $text, 'memory_limit 未设置时报 0');
    });

    test('服务器资源指标（F6）：CPU 是 counter，其余为 gauge', function (): void {
        $text = Metrics::render([
            'node' => [
                'cpu'       => ['modes' => ['user' => 12.5, 'idle' => 100.0]],
                'load'      => [0.5, 0.4, 0.3],
                'memory'    => [
                    'total'     => 8589934592,
                    'available' => 4294967296,
                    'used'      => 4294967296,
                    'percent'   => 50.0,
                ],
                'processes' => 123,
                'uptime'    => 86400,
                'redis'     => [
                    'used_memory'          => 1048576,
                    'connected_clients'    => 3,
                    'ops_per_second'       => 20,
                    'rejected_connections' => 0,
                ],
                'mysql'     => [
                    'threads_connected' => 5,
                    'threads_running'   => 1,
                    'connection_usage'  => 12.5,
                    'uptime_seconds'    => 3600,
                    'questions'         => 1000,
                    'slow_queries'      => 2,
                ],
            ],
        ]);

        // CPU 给抓取端的是累计秒数，由 rate() 求占用率；服务端不阻塞采样
        contains('# TYPE cccms_node_cpu_seconds_total counter', $text, 'CPU 必须是 counter');
        contains('# TYPE cccms_mysql_questions_total counter', $text, '累计查询数必须是 counter');
        contains('# TYPE cccms_node_load gauge', $text, '负载是瞬时值');
        contains('cccms_node_cpu_seconds_total{mode="idle"} 100', $text);
        contains('cccms_node_load{period="5"} 0.4', $text);
        contains('cccms_node_memory_used_percent 50', $text);
        contains('cccms_node_processes 123', $text);
        contains('cccms_node_uptime_seconds 86400', $text);
        contains('cccms_redis_connected_clients 3', $text);
        contains('cccms_mysql_connection_usage_percent 12.5', $text);
    });

    test('服务器资源指标（F6）：拿不到的项不写 0（未知 ≠ 零）', function (): void {
        // 模拟 Windows / 容器拿不到 /proc 与依赖不可用：只保留声明，不产生样本行
        $text = Metrics::render([
            'node' => [
                'cpu'       => null,
                'load'      => null,
                'memory'    => null,
                'processes' => null,
                'uptime'    => null,
                'redis'     => null,
                'mysql'     => null,
            ],
        ]);

        contains('# TYPE cccms_node_cpu_seconds_total counter', $text, '声明仍然存在（抓取端不会解析失败）');
        foreach (['cccms_node_processes', 'cccms_node_uptime_seconds', 'cccms_redis_used_memory_bytes'] as $name) {
            same(
                0,
                preg_match('/^' . $name . ' /m', $text),
                "{$name} 拿不到时不该写 0"
            );
        }
    });

    test('分位数：最近秩法，空样本返回 0', function (): void {
        same(0.0, Metrics::quantile([], 0.95), '空样本为 0');

        $sorted = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        same(50.0, Metrics::quantile($sorted, 0.5), 'P50');
        same(100.0, Metrics::quantile($sorted, 0.95), 'P95 落不到第 9 项时应取最接近的更大秩');
        same(10.0, Metrics::quantile($sorted, 0.0), 'P0 = 最小值');
        same(100.0, Metrics::quantile($sorted, 1.0), 'P100 = 最大值');
    });

    test('探针：php.ini 容量写法解析', function (): void {
        same(0, HealthProbe::parseBytes('-1'), '-1 表示不限制');
        same(0, HealthProbe::parseBytes(''), '空串');
        same(134217728, HealthProbe::parseBytes('128M'), '128M');
        same(1073741824, HealthProbe::parseBytes('1G'), '1G');
        same(65536, HealthProbe::parseBytes('64K'), '64K');
        same(1048576, HealthProbe::parseBytes('1048576'), '纯字节数');
    });

    test('探针：字节数展示', function (): void {
        same('512B', HealthProbe::bytes(512.0));
        same('1KB', HealthProbe::bytes(1024.0));
        same('1.5MB', HealthProbe::bytes(1572864.0));
        same('1GB', HealthProbe::bytes(1073741824.0));
    });

    test('白名单策略：默认只放行本机，空 IP 一律拒绝', function (): void {
        // 默认配置为 127.0.0.1,::1（容器内探活可用），公网来源一律 404
        ok(EndpointAccess::allowedIp('127.0.0.1'), '回环地址应在白名单内');
        ok(EndpointAccess::allowedIp('::1'), 'IPv6 回环应在白名单内');

        ok(!EndpointAccess::allowedIp('203.0.113.9'), '公网地址不该被放行');
        ok(!EndpointAccess::allowedIp('10.0.0.5'), '内网地址默认也不放行（需显式配置）');
        ok(!EndpointAccess::allowedIp(''), '取不到 IP 时按拒绝处理（fail-closed）');
        ok(!EndpointAccess::allowedIp('not-an-ip'), '非法 IP 不匹配任何条目');
    });

    test('探针：run() 返回四项检查且结构完整（需库与缓存）', function (): void {
        $report = HealthProbe::run();

        ok(is_bool($report['ok']), 'ok 必须是布尔');
        ok(is_string($report['time']) && $report['time'] !== '', 'time 必须有值');

        foreach (['mysql', 'redis', 'disk', 'memory'] as $name) {
            ok(isset($report['checks'][$name]), "缺少检查项 {$name}");
            ok(is_bool($report['checks'][$name]['ok']), "{$name}.ok 必须是布尔");
            ok(is_string($report['checks'][$name]['detail']), "{$name}.detail 必须是字符串");
        }

        // 本用例本身要求库与缓存可用（tests/run.php 的 requiresDb），因此这两项应为正常
        ok($report['checks']['mysql']['ok'], 'MySQL 应可用');
        ok($report['checks']['redis']['ok'], 'Redis 应可用');
    }, true);

    test('采集：collect() 的指标与 render() 的文本相互对应（需库与缓存）', function (): void {
        $snapshot = Metrics::collect();

        same(Metrics::WINDOW_SECONDS, $snapshot['window'], '窗口长度');
        ok(in_array($snapshot['mysql'], [0, 1], true), 'mysql 连通性应为 0/1');
        ok(in_array($snapshot['redis'], [0, 1], true), 'redis 连通性应为 0/1');
        ok($snapshot['requests']['total'] >= 0, '请求数不应为负');
        ok($snapshot['online'] >= 0, '在线会话数不应为负');

        $text = Metrics::render($snapshot);
        contains('cccms_up{component="mysql"} ' . $snapshot['mysql'], $text, '渲染结果应与采集结果一致');
        contains('cccms_online_sessions ' . $snapshot['online'], $text, '在线会话数应一致');
        contains(
            'cccms_http_requests_total{window="' . Metrics::WINDOW_SECONDS . 's"} ' . $snapshot['requests']['total'],
            $text,
            '请求数应一致'
        );
    }, true);
};
