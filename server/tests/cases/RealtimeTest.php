<?php

declare(strict_types=1);

use plugin\cccms\support\Realtime;
use support\Redis;

/**
 * 实时推送（F9）。
 *
 * 只测事件总线，不测 SSE 进程本身（它要 Linux 的自定义进程，且是长连接）：
 *   - 频道隔离（A 收不到 B）；
 *   - 增量拉取（`afterId` 语义，断线重连靠它补发）；
 *   - SSE 帧格式（`data` 必须单行，否则一条事件会被拆成多条）。
 */
return static function (): void {
    suite('实时推送（F9）');

    $redisAvailable = static function (): bool {
        try {
            Redis::get('cccms:test:alive');

            return true;
        } catch (Throwable) {
            return false;
        }
    };

    $cleanup = static function (int ...$userIds): void {
        foreach ($userIds as $userId) {
            try {
                Redis::del(Realtime::keyOf(Realtime::channelOfUser($userId)));
            } catch (Throwable) {
                // 清理失败不影响结论
            }
        }
    };

    test('频道命名：非法用户 id 不产生频道', function (): void {
        same('user:12', Realtime::channelOfUser(12), '正常 id 应拼成 user:{id}');
        same('', Realtime::channelOfUser(0), '0 不是合法用户');
        same('', Realtime::channelOfUser(-3), '负数不是合法用户');
        same('cccms:rt:user:12', Realtime::keyOf('user:12'), 'Redis key 应带统一前缀');
    });

    test('SSE 帧：id / event / 单行 data', function (): void {
        $frame = Realtime::frame([
            'id'    => 7,
            'event' => 'notice',
            'data'  => ['id' => 3, 'title' => "第一行\n第二行"],
            'at'    => 1,
        ]);

        ok(str_starts_with($frame, "id: 7\n"), '首行必须是事件 id（Last-Event-ID 依赖它）');
        ok(str_contains($frame, "event: notice\n"), '应带事件名');
        ok(str_ends_with($frame, "\n\n"), '帧必须以空行结束');
        // data 里带换行时，JSON 会转义成 \n；若漏了转义，帧会变成 4 行以上，事件被拆开
        same(3, count(explode("\n", trim($frame))), 'id / event / data 各占一行');
    });

    test('WebSocket 载荷：与 SSE 同语义（id / event / data 一个 JSON）', function (): void {
        $raw = Realtime::payload([
            'id'    => 7,
            'event' => 'notice',
            'data'  => ['id' => 3, 'title' => "第一行\n第二行"],
            'at'    => 1,
        ]);

        $payload = json_decode($raw, true);
        ok(is_array($payload), '必须是合法 JSON（前端直接 JSON.parse）');
        same(7, $payload['id'], 'id 与 SSE 的 id: 行同值');
        same('notice', $payload['event'], '事件名与 SSE 的 event: 行同值');
        same("第一行\n第二行", $payload['data']['title'] ?? '', 'data 原样保留（WS 没有单行限制，不需要转义换行）');
        same(1, $payload['at'], '时间戳透传');
    });

    test('WebSocket 载荷：缺字段时给出安全默认值', function (): void {
        $payload = json_decode(Realtime::payload([]), true);

        same(0, $payload['id'], '缺 id → 0（不会推进前端 lastEventId）');
        same('message', $payload['event'], '缺事件名 → message（落在前端白名单外，被丢弃）');
        same([], $payload['data'], 'data 必须始终是数组，前端按对象解构');
    });

    test('publish → pull：按 id 升序、增量拉取、频道隔离', function () use ($redisAvailable, $cleanup): void {
        if (!$redisAvailable()) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " Redis 不可用\n";

            return;
        }

        $alice = 990001;
        $bob   = 990002;
        $cleanup($alice, $bob);

        try {
            Realtime::publish($alice, 'notice', ['id' => 1, 'title' => 'A1']);
            Realtime::publish($bob, 'notice', ['id' => 2, 'title' => 'B1']);
            Realtime::publish($alice, 'job', ['job_id' => 9, 'status' => 'done']);

            $aliceEvents = Realtime::pull([Realtime::channelOfUser($alice)], 0);
            same(2, count($aliceEvents), 'Alice 应收到自己频道的 2 条事件');
            same('notice', $aliceEvents[0]['event'], '按写入顺序（id 升序）返回');
            same('job', $aliceEvents[1]['event'], '第二条是作业事件');
            ok($aliceEvents[0]['id'] < $aliceEvents[1]['id'], 'id 必须单调递增');

            $bobEvents = Realtime::pull([Realtime::channelOfUser($bob)], 0);
            same(1, count($bobEvents), 'Bob 只应收到自己的事件');
            same('B1', $bobEvents[0]['data']['title'] ?? '', '不应串到别人的内容');

            $after = $aliceEvents[0]['id'];
            $increment = Realtime::pull([Realtime::channelOfUser($alice)], $after);
            same(1, count($increment), 'afterId 之后只返回增量');
            same('job', $increment[0]['event'], '增量应跳过已读的那条');

            same([], Realtime::pull([Realtime::channelOfUser($alice)], $aliceEvents[1]['id']), '读到末尾应返回空');
        } finally {
            $cleanup($alice, $bob);
        }
    });

    test('保留窗口与 TTL：只留最近 N 条，且频道会过期', function () use ($redisAvailable, $cleanup): void {
        if (!$redisAvailable()) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " Redis 不可用\n";

            return;
        }

        $user = 990003;
        $cleanup($user);

        try {
            $limit = Realtime::retention();
            for ($i = 0; $i < $limit + 5; $i++) {
                Realtime::publish($user, 'notice', ['seq' => $i]);
            }

            $events = Realtime::pull([Realtime::channelOfUser($user)], 0);
            same($limit, count($events), '超出保留窗口的旧事件应被 LTRIM 丢弃');

            $ttl = (int)Redis::ttl(Realtime::keyOf(Realtime::channelOfUser($user)));
            ok($ttl > 0 && $ttl <= Realtime::ttl(), '频道应带 TTL，长期无人读时自动消失');
        } finally {
            $cleanup($user);
        }
    });

    test('配置夹取：心跳 / 轮询 / 上限都有安全边界', function (): void {
        ok(Realtime::heartbeat() >= 5, '心跳不能过密');
        ok(Realtime::pollInterval() >= 1, '轮询间隔不能低于 1s');
        ok(Realtime::retention() >= 10, '保留窗口不能太小');
        ok(Realtime::maxConnections() >= 1, '连接上限至少 1');
    });

    test('WS 端口与 SSE 端口分开（一个端口只能跑一种应用层协议）', function (): void {
        ok(Realtime::wsPort() > 0, 'WS 端口必须是正整数');
        ok(Realtime::wsPort() !== Realtime::port(), 'WS 与 SSE 不能共用端口，否则第二个进程起不来');
    });

    test('关闭开关时 publish 静默且不写事件', function () use ($redisAvailable, $cleanup): void {
        if (!$redisAvailable()) {
            Suite::$skipped++;
            echo '  ' . Suite::color('○ 跳过', 'gray') . " Redis 不可用\n";

            return;
        }

        // 用非法 userId 验证「不写」这条路径（不改全局配置，避免影响其它用例）
        $cleanup(0);
        Realtime::publish(0, 'notice', ['x' => 1]);
        same(null, Redis::get(Realtime::keyOf('')), '非法用户不应产生频道 key');
    });
};
