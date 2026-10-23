<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 实时推送的事件总线（F9）。
 *
 * ## 为什么用「Redis List + 轮询」而不是 pub/sub
 *
 * pub/sub 是**广播且不落盘**：订阅者晚连一秒，那一秒的事件就永远丢了；断线重连想补发
 * 也无从补。业务上能接受的语义是「**落库优先，推送只加速**」—— 消息本身在
 * `sys_notice_read` / `sys_job` 里，推送丢了前端下次拉取照样能看到。
 *
 * 于是选 List：`LPUSH` 写入 + `LTRIM` 只留最近 N 条 + `EXPIRE` 让长期无人读的频道自动消失，
 * 消费端按事件 id 过滤增量，还能按 `Last-Event-ID` 补发。
 *
 * 代价是轮询（默认 1s），换来的是「断线 30 秒重连不丢事件」与「不依赖 Redis 的阻塞连接」。
 *
 * ## 频道隔离
 *
 * 一个用户一个频道（`user:{id}`）。**不提供全局广播频道**：那需要把所有在线用户的
 * 未读都算一遍，还要防「A 租户看到 B 租户公告」。群发公告时由发送方按在线用户逐个
 * `publishMany()`，只写给真正连着的人。
 */
final class Realtime
{
    /** 频道 key 前缀：`cccms:rt:user:12` */
    public const KEY_PREFIX = 'cccms:rt:';

    /** 全局事件序号：给 SSE 的 `id:` 用，保证跨频道单调递增 */
    private const SEQ_KEY = 'cccms:rt:seq';

    /** 配置缓存（与 Terminal 一致：进程启动读入，改完 `php webman reload`） */
    private static ?array $configCache = null;

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        try {
            $config = config('plugin.cccms.realtime');
        } catch (Throwable) {
            $config = null;
        }

        if (!is_array($config) || $config === []) {
            $file   = dirname(__DIR__) . '/config/realtime.php';
            $loaded = is_file($file) ? require $file : null;
            $config = is_array($loaded) ? $loaded : ['enable' => false];
        }

        return self::$configCache = $config;
    }

    public static function enabled(): bool
    {
        return (bool)(self::config()['enable'] ?? false);
    }

    public static function port(): int
    {
        return (int)(self::config()['port'] ?? 8787);
    }

    public static function host(): string
    {
        $host = (string)(self::config()['host'] ?? '0.0.0.0');

        return $host !== '' ? $host : '0.0.0.0';
    }

    public static function heartbeat(): int
    {
        return max(5, min(60, (int)(self::config()['heartbeat'] ?? 15)));
    }

    public static function pollInterval(): int
    {
        return max(1, min(10, (int)(self::config()['poll'] ?? 1)));
    }

    public static function retention(): int
    {
        return max(10, min(1000, (int)(self::config()['retention'] ?? 100)));
    }

    public static function ttl(): int
    {
        return max(60, min(86400, (int)(self::config()['ttl'] ?? 600)));
    }

    public static function maxConnections(): int
    {
        return max(1, min(10000, (int)(self::config()['max_connections'] ?? 500)));
    }

    /** 用户频道名（id 必须为正；非法 id 返回空串，调用方据此跳过） */
    public static function channelOfUser(int $userId): string
    {
        return $userId > 0 ? 'user:' . $userId : '';
    }

    /** Redis 里的完整 key */
    public static function keyOf(string $channel): string
    {
        return self::KEY_PREFIX . $channel;
    }

    /**
     * 给一个用户推事件。
     *
     * **失败静默**：推送是加速层，Redis 挂了不该让「发公告 / 作业完成」这类主流程失败。
     *
     * @param array<string,mixed> $data
     */
    public static function publish(int $userId, string $event, array $data = []): void
    {
        if ($userId <= 0 || $event === '' || !self::enabled()) {
            return;
        }

        try {
            $id      = (int)Redis::incr(self::SEQ_KEY);
            $channel = self::channelOfUser($userId);
            $key     = self::keyOf($channel);

            Redis::lpush($key, (string)json_encode([
                'id'    => $id,
                'event' => $event,
                'data'  => $data,
                'at'    => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 只留最近 N 条：SSE 断线重连按 id 补发，更早的靠接口补拉
            Redis::ltrim($key, 0, self::retention() - 1);
            Redis::expire($key, self::ttl());
        } catch (Throwable) {
            // 静默降级为「纯拉取」
        }
    }

    /**
     * 批量推给多个用户（公告群发用）。
     *
     * 只应传入**在线用户**：给全库用户各写一条没人读的 Redis 列表，等于用推送制造垃圾。
     *
     * @param array<int,int>      $userIds
     * @param array<string,mixed> $data
     */
    public static function publishMany(array $userIds, string $event, array $data = []): void
    {
        if ($event === '' || !self::enabled()) {
            return;
        }

        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            self::publish($userId, $event, $data);
        }
    }

    /**
     * 拉取频道里 id 大于 `$afterId` 的事件（按 id 升序）。
     *
     * @param  array<int,string> $channels
     * @return array<int,array{id:int,event:string,data:array<string,mixed>,at:int}>
     */
    public static function pull(array $channels, int $afterId): array
    {
        $out = [];

        foreach (array_unique(array_filter($channels)) as $channel) {
            try {
                $rows = Redis::lrange(self::keyOf($channel), 0, self::retention() - 1);
            } catch (Throwable) {
                continue;
            }

            foreach ((array)$rows as $raw) {
                $event = json_decode((string)$raw, true);
                if (!is_array($event) || (int)($event['id'] ?? 0) <= $afterId) {
                    continue;
                }
                $out[] = [
                    'id'    => (int)$event['id'],
                    'event' => (string)($event['event'] ?? 'message'),
                    'data'  => is_array($event['data'] ?? null) ? $event['data'] : [],
                    'at'    => (int)($event['at'] ?? 0),
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $out;
    }

    /**
     * 格式化为 SSE 帧。
     *
     * `data` 必须是**单行**：SSE 按行解析，JSON 里的换行会把一条事件拆成多条。
     */
    public static function frame(array $event): string
    {
        $data = (string)json_encode($event['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'id: ' . (int)$event['id'] . "\n"
            . 'event: ' . (string)$event['event'] . "\n"
            . 'data: ' . $data . "\n\n";
    }

    /**
     * 格式化为 WebSocket 消息（与 SSE 的 `id` / `event` / `data` 语义完全一致）。
     *
     * SSE 把 id / event 放在协议层（`id:` / `event:` 行），WebSocket 没有这一层可用，
     * 于是整体序列化成一个 JSON 对象；前端两种 driver 都还原成 `{id, event, data}` 再分发，
     * 所以页面代码不需要区分通道（见 `useRealtime.ts`）。
     *
     * @param array{id?:int,event?:string,data?:array<string,mixed>,at?:int} $event
     */
    public static function payload(array $event): string
    {
        return (string)json_encode([
            'id'    => (int)($event['id'] ?? 0),
            'event' => (string)($event['event'] ?? 'message'),
            'data'  => is_array($event['data'] ?? null) ? $event['data'] : [],
            'at'    => (int)($event['at'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * WebSocket 端口。
     *
     * 与 SSE **分开**：一个端口只能跑一种应用层协议，且两种通道需要能同时在线
     * （前端用 `VITE_REALTIME_DRIVER` 二选一，但灰度期间两边的连接都得能连上）。
     */
    public static function wsPort(): int
    {
        return (int)(self::config()['ws_port'] ?? 8789);
    }
}
