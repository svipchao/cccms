<?php

declare(strict_types=1);

namespace plugin\cccms\process;

use plugin\cccms\support\Realtime;
use plugin\cccms\support\TokenBlacklist;
use plugin\cccms\support\TokenService;
use support\Log;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

/**
 * WebSocket 推送进程（F9 剩余部分）。
 *
 * ## 与 SSE 的关系
 *
 * 两者共用同一个事件源：`Realtime`（Redis List）。这里**不引入 pub/sub** ——
 * 订阅者数量远不足以抵消它「不落盘、不补发」的缺点，而 `Realtime::pull()` 的
 * 按用户分组轮询已经能给出「断线按 last_id 补发」的语义。
 *
 * 事件名与载荷也完全一致：SSE 把 `id` / `event` 拆到协议层，WebSocket 用一个 JSON
 * 对象承载（`Realtime::payload()`），前端 `useRealtime` 两种 driver 还原成同一种
 * `{id, event, data}` 再分发，页面代码零改动。
 *
 * ## 为什么现在才补
 *
 * 后台场景都是服务端单向下行，SSE 已够用。WebSocket 的价值是**客户端上行**：
 * 心跳回执、后续的协作 / 终端输出流式回传。这里先把通道与鉴权立起来，
 * 上行只实现 `{"type":"ping"}` → `pong`。
 *
 * ## 鉴权
 *
 * 浏览器原生 `WebSocket` 同样不能带自定义请求头，令牌只能走 query：
 * `ws://host:8789/ws?token=...&last_id=...`。验签之外必须查 `TokenBlacklist`，
 * 否则「强制下线」在实时通道上等于没做（签名仍然有效）。
 *
 * ⚠️ 回调里**不能让异常逃出去**：workerman 对 `onWebSocketConnect` 抛出的异常执行
 * `Worker::stopAll()`，一条坏令牌就能把整个推送进程停掉。
 *
 * ## 状态管理
 *
 * 与 `Sse` 一致：连接状态存在本进程的 `$clients` 里（`spl_object_id` 作键），
 * 不往 `TcpConnection` 上挂动态属性（PHP 8.2 起对动态属性报 deprecated）。
 */
class Websocket
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
            Log::info('realtime: disabled by config, WebSocket worker stays idle');

            return;
        }

        $this->lastHeartbeat = time();
        Timer::add(Realtime::pollInterval(), function (): void {
            $this->tick();
        });
    }

    /** 握手阶段鉴权：通过则登记连接，否则直接关闭（不抛异常，见类注释） */
    public function onWebSocketConnect(TcpConnection $connection, Request $request): void
    {
        if (!Realtime::enabled()) {
            $this->reject($connection, 'realtime disabled');

            return;
        }

        $token = (string)$request->get('token', '');

        try {
            $claims = TokenService::verify($token);
        } catch (Throwable) {
            $this->reject($connection, 'invalid token');

            return;
        }

        $jti = (string)($claims['jti'] ?? '');
        if ($jti !== '' && TokenBlacklist::isRevoked($jti)) {
            $this->reject($connection, 'token revoked');

            return;
        }

        $userId = (int)($claims['sub'] ?? 0);
        if ($userId <= 0) {
            $this->reject($connection, 'invalid subject');

            return;
        }

        if (count($this->clients) >= Realtime::maxConnections()) {
            $this->reject($connection, 'too many connections');

            return;
        }

        $this->clients[spl_object_id($connection)] = [
            'connection' => $connection,
            'userId'     => $userId,
            // 重连时前端带上已收到的最大事件 id，补发窗口与 SSE 同一套语义
            'lastId'     => max(0, (int)$request->get('last_id', 0)),
        ];
    }

    /** 上行：目前只有心跳；业务消息留给按需扩展（不要在这里接受任何身份相关的字段） */
    public function onMessage(TcpConnection $connection, mixed $data): void
    {
        if (!isset($this->clients[spl_object_id($connection)])) {
            return;
        }

        $message = json_decode((string)$data, true);
        if (!is_array($message)) {
            return;
        }

        if (($message['type'] ?? '') === 'ping') {
            // id 固定 0：不会推进前端 lastEventId，也不会被当成业务事件分发
            $this->write($connection, Realtime::payload(['id' => 0, 'event' => 'pong']));
        }
    }

    public function onClose(TcpConnection $connection): void
    {
        unset($this->clients[spl_object_id($connection)]);
    }

    /** 鉴权失败：握手响应还没发出去，直接断连即可（客户端表现为 error + 重连退避） */
    private function reject(TcpConnection $connection, string $reason): void
    {
        Log::debug('realtime: websocket handshake rejected', ['reason' => $reason]);
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
                    if (!$this->write($client['connection'], Realtime::payload($event))) {
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

        // 应用层心跳：代理默认 60s 掐断空闲连接，定期写一帧让链路保持「有数据」
        foreach ($this->clients as $client) {
            $this->write($client['connection'], Realtime::payload(['id' => 0, 'event' => 'ping']));
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
