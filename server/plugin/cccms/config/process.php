<?php

use plugin\cccms\process\Crontab;
use plugin\cccms\process\Queue;
use plugin\cccms\process\Sse;
use plugin\cccms\process\Websocket;

// SSE 端口：容器 / 反代换端口时用环境变量，避免改代码。
// 注意也要同步 `config/realtime.php` 的 port —— 那里只用于文档与前端拼 URL 的提示，
// 真正监听的是下面这个值（进程配置在框架启动时读入，不经过 config()）。
// 取 8788 是因为 8787 已被 `config/server.php` 的 HTTP 服务占用。
$ssePort = (int)(getenv('CCCMS_SSE_PORT') ?: 8788);

// WebSocket 端口：与 SSE 分开 —— 一个端口只能跑一种应用层协议
$wsPort = (int)(getenv('CCCMS_WS_PORT') ?: 8789);

return [
    // 定时任务调度进程（仅 Linux/macOS 生效）
    'crontab' => [
        'handler'    => Crontab::class,
        'count'      => 1,
        'reloadable' => false,
    ],
    // 异步作业消费进程（仅 Linux/macOS 生效）
    // 与 crontab 分开：调度延迟不该被长作业拖住，反之亦然
    'job_queue' => [
        'handler'    => Queue::class,
        'count'      => 1,
        'reloadable' => false,
    ],
    // 实时推送（SSE）：裸 TCP 自己写 SSE 帧，见 process/Sse.php 的类注释
    'realtime_sse' => [
        'handler'    => Sse::class,
        'listen'     => 'tcp://0.0.0.0:' . $ssePort,
        'count'      => 1,
        // 长连接进程不参与 reload（reload 会掐断所有在线连接），改配置请 restart
        'reloadable' => false,
    ],
    // 实时推送（WebSocket）：与 SSE 共用 Realtime 事件源，见 process/Websocket.php 的类注释
    'realtime_ws' => [
        'handler'    => Websocket::class,
        'listen'     => 'websocket://0.0.0.0:' . $wsPort,
        'count'      => 1,
        'reloadable' => false,
    ],
];
