<?php

declare(strict_types=1);

/**
 * 实时推送（F9）：SSE / WebSocket 通道配置。
 *
 * ## 为什么是独立进程 + 独立端口
 *
 * SSE 是**长连接**：一个连接要在几十分钟里持续吐字节。webman 的 HTTP worker 是
 * 「请求 → 响应 → 释放」，控制器返回后就没有句柄能继续写入了。硬要在 HTTP worker
 * 里挂长连接，等于用业务 worker 的数量去换在线人数（一个 worker 占满就再也接不了请求）。
 *
 * 所以推送走独立的 workerman 进程（`process/Sse.php`），用裸 TCP 自己写 HTTP 头 ——
 * workerman 的 HTTP 协议每次 `send()` 都会补一个完整的响应头，分块推送反而要绕。
 *
 * ## 部署要点
 *
 * 1. 端口要对内网暴露（Docker 记得 `-p`），Nginx 反代见 `docs/nginx.conf.example`；
 * 2. 必须关掉代理缓冲（`proxy_buffering off` / `X-Accel-Buffering: no`），否则事件会
 *    攒在 Nginx 里直到连接结束 —— 表现为「页面一直没反应，刷新后才看到」；
 * 3. 多实例部署时每个实例各跑一个进程，Redis 列表是共享的，谁连到哪台都能收到。
 *
 * ## 降级
 *
 * 进程没起 / Redis 不可用时：`publish()` 静默失败，前端 EventSource 连不上会退化成
 * 纯轮询（未读数、任务状态本来就靠接口拉取）。**推送只是加速，不承担一致性**。
 */
return [
    // 总开关：关掉后进程不监听，publish 直接返回
    'enable'     => true,

    // 监听地址：0.0.0.0 便于容器内访问；对外暴露交给 Nginx。
    // 端口**不能**与 `config/server.php` 的 listen（默认 8787）相同，否则第二个进程起不来。
    'host'       => '0.0.0.0',
    'port'       => 8788,

    // WebSocket 端口（与 SSE 分开：一个端口只能跑一种应用层协议）。
    // 真正监听的是 `config/process.php` 里 `realtime_ws` 的 listen（可用 CCCMS_WS_PORT 覆盖），
    // 这里的值只供文档与前端拼地址时参考。
    'ws_port'    => 8789,

    // 心跳间隔（秒）：注释行 `: ping`，用来让代理与浏览器确认连接还活着
    'heartbeat'  => 15,

    // 拉取间隔（秒）：独立进程每秒扫一次 Redis 列表
    'poll'       => 1,

    // 每个用户频道保留的事件条数：断线重连时按 Last-Event-ID 补发，超出即丢（落库优先）
    'retention'  => 100,

    // 频道 key 的 TTL（秒）：用户长期不登录，频道自然消失，不在 Redis 里留垃圾
    'ttl'        => 600,

    // 单进程最大连接数：超过直接 503，避免把进程内存拖爆
    'max_connections' => 500,
];
