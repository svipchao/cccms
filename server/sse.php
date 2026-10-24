#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 单独启动「实时推送 · SSE」进程（默认 8788）的入口，本地开发 / Windows 排查用。
 *
 * ## 为什么需要这个文件
 *
 * `php start.php start`（等于 `php webman start`）在 Windows 上**不启动自定义进程**：
 * `Webman\App::run()` 里只有 `DIRECTORY_SEPARATOR === '/'` 分支才会 `worker_start()`，
 * 所以 8788 / 8789 在那种启动方式下永远没人监听 —— 浏览器控制台只会得到
 * `ERR_CONNECTION_REFUSED`。
 *
 * 两个可行做法：
 *
 *   1. `php windows.php`（`windows.bat` 双击等价）：它把 `config/process.php` 与各插件的
 *      进程配置逐个写成启动文件，再由 workerman 的 `forkWorkersForWindows()` 各 fork 一个
 *      子进程 —— HTTP(8787) / crontab / job_queue / SSE(8788) / WS(8789) 会一起起来；
 *   2. 本文件：**只**把 SSE 那一个进程拉起来。调推送时不必连带重启 HTTP 服务，
 *      也不依赖整个 windows 进程树。
 *
 * ## 用法
 *
 *   php sse.php start                              # 前台运行，Ctrl+C 退出
 *   set CCCMS_SSE_PORT=8790 && php sse.php start   # Windows 换端口
 *   CCCMS_SSE_PORT=8790 php sse.php start          # Linux / macOS 换端口
 *
 * 端口来自 `config/plugin/cccms/process.php` 的 `realtime_sse.listen`（默认 8788）。
 * 前端不需要额外配置：`.env` 里没有 `VITE_SSE_URL` 时，`useRealtime.ts` 直连
 * `http://<hostname>:8788/stream`。
 *
 * ## 验证
 *
 *   curl -N "http://127.0.0.1:8788/stream?token=<jwt>"
 *
 * 应当立刻返回 `HTTP/1.1 200` + `text/event-stream`，随后是 `retry: 3000` /
 * `: connected`，之后每 15 秒一行 `: ping`。拿到 401 说明端口是通的、只是令牌
 * 过期或被拉黑（`TokenBlacklist`），与进程无关。
 *
 * ## 限制
 *
 * Windows 上 workerman 只支持 `start`（前台）：`stop` / `reload` / `status` 一概不可用，
 * 停进程用 Ctrl+C 或任务管理器；`count` 必须为 1。生产请用 Linux 的
 * `php start.php start -d`，不要用这个文件。
 */

chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use support\App;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

ini_set('display_errors', 'on');
error_reporting(E_ALL);

if (class_exists('Dotenv\Dotenv') && file_exists(base_path() . '/.env')) {
    if (method_exists('Dotenv\Dotenv', 'createUnsafeImmutable')) {
        Dotenv::createUnsafeImmutable(base_path())->load();
    } else {
        Dotenv::createMutable(base_path())->load();
    }
}

App::loadAllConfig(['route']);

// 进程内的时间格式（心跳日志、令牌相关展示）与 HTTP 服务保持一致
if ($timezone = config('app.default_timezone')) {
    date_default_timezone_set((string)$timezone);
}

$errorReporting = config('app.error_reporting');
if (isset($errorReporting)) {
    error_reporting((int)$errorReporting);
}

$processConfig = config('plugin.cccms.process')['realtime_sse'] ?? null;
if (!is_array($processConfig) || $processConfig === []) {
    fwrite(STDERR, "找不到 realtime_sse 进程配置，请检查 config/plugin/cccms/process.php\r\n");
    exit(1);
}

// 端口已被占用时给一句人话：最常见的情况是 `php windows.php` 已经把 8788 起起来了
if (in_array('start', $argv, true)) {
    $port = listen_port((string)($processConfig['listen'] ?? ''));
    if ($port > 0 && ($probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3)) !== false) {
        fclose($probe);
        fwrite(STDERR, "端口 {$port} 已在监听：可能 `php windows.php` 已经启动过 realtime_sse，"
            . "先停掉它再运行本脚本（或改用 CCCMS_SSE_PORT 换端口）。\r\n");
        exit(1);
    }
}

echo 'SSE 推送进程：', ($processConfig['listen'] ?? ''), PHP_EOL;

worker_start('realtime_sse', $processConfig);

// Windows 下这两个静态属性不会被 App::run() 初始化，需要自己补（与 windows.php 生成的启动文件一致）
TcpConnection::$defaultMaxPackageSize = config('server')['max_package_size'] ?? 10 * 1024 * 1024;
Worker::$logFile = config('server')['log_file'] ?? Worker::$logFile;

Worker::runAll();

/**
 * 从 `tcp://0.0.0.0:8788` 里取端口；非 TCP 监听返回 0（不做占用预检）。
 */
function listen_port(string $listen): int
{
    if (!str_starts_with($listen, 'tcp://')) {
        return 0;
    }

    $port = substr($listen, (int)strrpos($listen, ':') + 1);

    return ctype_digit($port) ? (int)$port : 0;
}
