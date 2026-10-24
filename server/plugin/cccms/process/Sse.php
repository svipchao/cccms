<?php

declare(strict_types=1);

namespace plugin\cccms\process;

use plugin\cccms\app\middleware\Cors;
use plugin\cccms\support\Realtime;
use plugin\cccms\support\TokenBlacklist;
use plugin\cccms\support\TokenService;
use support\Log;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * SSE 推送进程（F9）。
 *
 * ## 为什么是裸 TCP 而不是 `http://` 协议
 *
 * workerman 的 HTTP 协议每次 `send()` 都会补一整套响应头（状态行 + Content-Length）。
 * 用它做 SSE 的话，第二帧就成了「另一个 HTTP 响应」，浏览器不会当作同一条事件流。
 * 这里直接用 `tcp://`，握手时自己写一次响应头，之后按 SSE 帧格式发文本 ——
 * `Content-Length` 缺席 + `Connection: keep-alive` 正好是 SSE 期望的语义。
 *
 * ## 鉴权
 *
 * 浏览器的 `EventSource` **不能带自定义请求头**，所以令牌走 query：`GET /stream?token=...`。
 * 代价是令牌可能进代理的 access log —— 部署文档要求对 `/stream` 关闭 access log
 * （或仅在反代层记录 IP）。
 *
 * 除了验签，还要查 `TokenBlacklist`：登出 / 被踢的令牌签名仍然有效，不查黑名单会让
 * 「强制下线」在实时通道上失效 —— 被踢的人还能继续收推送。
 *
 * ## 跨域
 *
 * `EventSource` 与普通 XHR 一样受 CORS 约束。开发时前端跑在 Vite 的 5173、推送在 8788，
 * 是**跨域**请求 —— 响应里没有 `Access-Control-Allow-Origin` 时，浏览器给出的是
 * `net::ERR_FAILED 200 (OK)`（拿到了 200 却不让读），用 curl 完全复现不出来。
 * 白名单复用 HTTP 侧的 `Cors::matchOrigin()`，即 `.env` 的 `CORS_ORIGIN`，只有一份配置。
 *
 * ## 连接管理
 *
 * 状态存在本进程的 `$clients` 里（`spl_object_id` 作键），不往 `TcpConnection` 上挂动态属性
 * （PHP 8.2 起对动态属性报 deprecated）。`onClose` 时清理。
 *
 * 每个 tick 按 `userId` 分组拉取：同一用户开 3 个标签页只查一次 Redis。
 */
class Sse
{
    /**
     * @var array<int,array{connection:TcpConnection,userId:int,lastId:int}>
     */
    private array $clients = [];

    private int $lastHeartbeat = 0;

    public function onWorkerStart(Worker $worker): void
    {
        if (!Realtime::enabled()) {
            // 开关关掉时进程照常起来但不监听任何东西：`enable=false` 不该影响 webman 启动
            Log::info('realtime: disabled by config, SSE worker stays idle');

            return;
        }

        $this->lastHeartbeat = time();
        Timer::add(Realtime::pollInterval(), function (): void {
            $this->tick();
        });
    }

    public function onMessage(TcpConnection $connection, mixed $buffer): void
    {
        $id = spl_object_id($connection);
        // SSE 是单向通道：握手成功后客户端再发什么都不用理（也避免被当作新的握手）
        if (isset($this->clients[$id])) {
            return;
        }

        $raw  = (string)$buffer;
        $line = strtok($raw, "\r\n");
        if (!is_string($line) || preg_match('#^GET\s+(\S+)\s+HTTP/1\.[01]$#', $line, $matches) !== 1) {
            $this->reject($connection, '400 Bad Request', 'only GET is supported');

            return;
        }

        $this->handshake($connection, $matches[1], self::originOf($raw));
    }

    public function onClose(TcpConnection $connection): void
    {
        unset($this->clients[spl_object_id($connection)]);
    }

    private function handshake(TcpConnection $connection, string $target, string $origin = ''): void
    {
        $parts = parse_url($target);
        if (($parts['path'] ?? '') !== '/stream') {
            $this->reject($connection, '404 Not Found', 'use GET /stream');

            return;
        }

        parse_str((string)($parts['query'] ?? ''), $query);
        $token = (string)($query['token'] ?? '');

        try {
            $claims = TokenService::verify($token);
        } catch (Throwable) {
            $this->reject($connection, '401 Unauthorized', 'invalid token');

            return;
        }

        $jti = (string)($claims['jti'] ?? '');
        if ($jti !== '' && TokenBlacklist::isRevoked($jti)) {
            $this->reject($connection, '401 Unauthorized', 'token revoked');

            return;
        }

        $userId = (int)($claims['sub'] ?? 0);
        if ($userId <= 0) {
            $this->reject($connection, '401 Unauthorized', 'invalid subject');

            return;
        }

        if (count($this->clients) >= Realtime::maxConnections()) {
            $this->reject($connection, '503 Service Unavailable', 'too many connections');

            return;
        }

        $this->respond($connection, $origin);

        $this->clients[spl_object_id($connection)] = [
            'connection' => $connection,
            'userId'     => $userId,
            // Last-Event-ID：浏览器重连时由前端拼进 query（EventSource 自己的重连不带这个头）
            'lastId'     => max(0, (int)($query['last_id'] ?? 0)),
        ];
    }

    /** 写一次响应头，之后这条连接就是纯事件流 */
    private function respond(TcpConnection $connection, string $origin = ''): void
    {
        $allowed = Cors::matchOrigin($origin);

        $headers = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream; charset=utf-8\r\n"
            . "Cache-Control: no-cache, no-transform\r\n"
            . "Connection: keep-alive\r\n"
            // 关键：告诉 Nginx 不要缓冲，否则事件会攒到连接结束才吐出来
            . "X-Accel-Buffering: no\r\n";

        // 跨域：EventSource 一样受 CORS 约束。开发时前端在 5173、推送在 8788，
        // 少了 Allow-Origin 浏览器直接报 `net::ERR_FAILED 200 (OK)`（curl 看不出来）。
        // 白名单取 `.env` 的 CORS_ORIGIN，与 HTTP 接口的 Cors 中间件共用一份。
        if ($origin !== '') {
            $headers .= "Vary: Origin\r\n";
        }
        if ($allowed !== '') {
            $headers .= "Access-Control-Allow-Origin: {$allowed}\r\n";
        }

        $connection->send($headers . "\r\n");
        $connection->send("retry: 3000\n: connected\n\n");
    }

    /**
     * 从原始请求头里取 `Origin`（跨域才带，同源请求没有）。
     *
     * 裸 TCP 拿不到 `Request` 对象，这里自己按行找；值不可能含 CRLF —— 有的话
     * 早就被行分隔切成两条头了，所以写进响应头不存在头注入。
     */
    private static function originOf(string $raw): string
    {
        foreach (explode("\r\n", $raw) as $header) {
            if (stripos($header, 'Origin:') === 0) {
                return trim(substr($header, 7));
            }
        }

        return '';
    }

    private function reject(TcpConnection $connection, string $status, string $message): void
    {
        $connection->send(
            "HTTP/1.1 {$status}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($message) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $message
        );
        $connection->close();
    }

    /** 每秒一次：先补事件，再按间隔发心跳 */
    private function tick(): void
    {
        $groups = [];
        foreach ($this->clients as $id => $client) {
            $groups[$client['userId']][] = $id;
        }

        foreach ($groups as $userId => $ids) {
            // 同一用户多个连接：取最小的 lastId 拉一次，再各自丢弃已收到的
            $after = null;
            foreach ($ids as $id) {
                $lastId = $this->clients[$id]['lastId'];
                $after  = $after === null ? $lastId : min($after, $lastId);
            }

            $events = Realtime::pull([Realtime::channelOfUser((int)$userId)], (int)$after);
            if ($events === []) {
                continue;
            }

            foreach ($ids as $id) {
                $client = $this->clients[$id] ?? null;
                if ($client === null) {
                    continue;
                }
                foreach ($events as $event) {
                    if ($event['id'] <= $client['lastId']) {
                        continue;
                    }
                    if (!$this->write($client['connection'], Realtime::frame($event))) {
                        break;
                    }
                    $this->clients[$id]['lastId'] = $event['id'];
                }
            }
        }

        $now = time();
        if ($now - $this->lastHeartbeat < Realtime::heartbeat()) {
            return;
        }
        $this->lastHeartbeat = $now;

        // 注释行（以 `:` 开头）不会触发前端事件，只用来探测连接是否还活着
        foreach ($this->clients as $client) {
            $this->write($client['connection'], ": ping\n\n");
        }
    }

    /** 写失败（对端已断）就立即摘掉，别等到下一次心跳才发现 */
    private function write(TcpConnection $connection, string $payload): bool
    {
        try {
            return $connection->send($payload) !== false;
        } catch (Throwable) {
            unset($this->clients[spl_object_id($connection)]);

            return false;
        }
    }
}
