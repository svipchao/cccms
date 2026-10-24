<?php

declare(strict_types=1);

use plugin\cccms\app\middleware\Cors;
use Webman\Config;
use Webman\Http\Request;

/**
 * 只加载 `plugin/cccms/config/cors.php`（其余配置排除）：
 * 测试运行器没有 webman 运行时，include route.php / middleware.php 等会直接报错。
 */
$loadCorsConfig = static function (string $origin): void {
    putenv('CORS_ORIGIN=' . $origin);
    Config::load(
        __DIR__ . '/../../plugin/cccms/config',
        ['app', 'auth', 'bootstrap', 'database', 'exception', 'filesystem', 'log', 'middleware', 'process', 'response', 'route', 'upgrade'],
        'plugin.cccms'
    );
};

$originRequest = static function (
    string $origin,
    string $method = 'GET',
    string $path = '/ping',
    array $headers = []
): Request {
    $raw = "{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n";
    if ($origin !== '') {
        $raw .= "Origin: {$origin}\r\n";
    }
    foreach ($headers as $name => $value) {
        $raw .= "{$name}: {$value}\r\n";
    }
    return new Request($raw . "\r\n");
};

return static function () use ($loadCorsConfig, $originRequest): void {
    suite('跨域白名单（P1-6）');

    test('白名单命中：回显具体 Origin 并声明 Vary', function () use ($loadCorsConfig, $originRequest): void {
        $loadCorsConfig('https://ok.example.com, https://ops.example.com');
        $response = (new Cors())->process($originRequest('https://ops.example.com'), static fn () => response('ok'));

        same('https://ops.example.com', $response->getHeader('Access-Control-Allow-Origin'), '应回显命中白名单的来源');
        same('Origin', $response->getHeader('Vary'), '回显具体来源时必须声明 Vary，否则缓存会串来源');
        same('false', $response->getHeader('Access-Control-Allow-Credentials'));
    });

    test('白名单未命中：不下发任何 CORS 放行头', function () use ($loadCorsConfig, $originRequest): void {
        $loadCorsConfig('https://ok.example.com');
        $response = (new Cors())->process($originRequest('https://evil.example.com'), static fn () => response('ok'));

        same(null, $response->getHeader('Access-Control-Allow-Origin'), '未命中不应下发 Allow-Origin');
        ok(
            !str_contains(strtolower((string)json_encode($response->getHeaders())), 'access-control-allow-'),
            '未命中不应下发任何 Access-Control-Allow-* 头'
        );
        same('Origin', $response->getHeader('Vary'), '仍应声明 Vary，避免缓存把无 CORS 头的响应复用给已放行来源');
    });

    test('默认（CORS_ORIGIN 留空）：仅同源，任何跨域来源都不放行', function () use ($loadCorsConfig, $originRequest): void {
        $loadCorsConfig('');
        $response = (new Cors())->process($originRequest('https://any.example.com'), static fn () => response('ok'));

        same(null, $response->getHeader('Access-Control-Allow-Origin'), '默认配置不应放行跨域来源');
    });

    test('同源请求（无 Origin）：不下发 Allow-Origin，也不声明 Vary', function () use ($loadCorsConfig, $originRequest): void {
        $loadCorsConfig('https://ok.example.com');
        $response = (new Cors())->process($originRequest(''), static fn () => response('ok'));

        same(null, $response->getHeader('Access-Control-Allow-Origin'));
        same(null, $response->getHeader('Vary'), '同源请求无需声明 Vary');
    });

    test('预检 OPTIONS：命中白名单返回 204 且不进入业务处理', function () use ($loadCorsConfig, $originRequest): void {
        $loadCorsConfig('https://ok.example.com');
        $called = false;
        $response = (new Cors())->process(
            $originRequest('https://ok.example.com', 'OPTIONS', '/user'),
            static function () use (&$called) {
                $called = true;
                return response('should not reach');
            }
        );

        same(204, $response->getStatusCode(), '预检应直接返回 204');
        ok(!$called, '预检请求不应进入业务处理');
        same('https://ok.example.com', $response->getHeader('Access-Control-Allow-Origin'));
    });

    test('跨域白名单的 Allow-Headers 放行 X-Action-Id / X-Session-Id（否则预检直接拦掉）', function () use (
        $loadCorsConfig,
        $originRequest
    ): void {
        $loadCorsConfig('https://ok.example.com');
        $response = (new Cors())->process($originRequest('https://ok.example.com'), static fn () => response('ok'));
        $allowed  = (string)$response->getHeader('Access-Control-Allow-Headers');

        ok(str_contains($allowed, 'X-Action-Id'), '操作 ID 是前端发的，跨域时必须显式放行');
        ok(str_contains($allowed, 'X-Session-Id'), '会话 ID 是前端发的，跨域时必须显式放行');
    });

    test('合法的 X-Action-Id / X-Session-Id 落到请求上，且不影响后端生成的 trace_id', function () use (
        $loadCorsConfig,
        $originRequest
    ): void {
        $loadCorsConfig('');
        $request = $originRequest('', 'GET', '/user', [
            'X-Action-Id'  => 'act-20260918-a1b2c3d4',
            'X-Session-Id' => 'ses-20260918-ffeeddcc',
        ]);
        $seen = [];

        $response = (new Cors())->process($request, static function (Request $req) use (&$seen) {
            $seen = ['trace' => $req->traceId, 'action' => $req->actionId, 'session' => $req->sessionId];
            return response('ok');
        });

        same('act-20260918-a1b2c3d4', $seen['action'], '后续中间件应看到调用方给的操作 ID');
        same('ses-20260918-ffeeddcc', $seen['session'], '后续中间件应看到调用方给的会话 ID');
        same(1, preg_match('/^\d{12}-[0-9a-f]{12}$/', (string)$seen['trace']), '请求 ID 仍由后端生成');
        ok($seen['trace'] !== $seen['action'], '请求级与操作级是两个独立的 ID');
        same($seen['trace'], $response->getHeader('X-Trace-Id'), '响应头回写后端生成的请求 ID');
    });

    test('非法 X-Action-Id / X-Session-Id（含空格 / 超长）被丢弃为空串', function () use (
        $loadCorsConfig,
        $originRequest
    ): void {
        $loadCorsConfig('');
        $request = $originRequest('', 'GET', '/user', [
            'X-Action-Id'  => 'bad value with spaces',
            'X-Session-Id' => str_repeat('x', 40),
        ]);
        $seen = [];

        (new Cors())->process($request, static function (Request $req) use (&$seen) {
            $seen = ['action' => $req->actionId, 'session' => $req->sessionId];
            return response('ok');
        });

        same('', $seen['action'], '非法操作 ID 不能原样写进日志');
        same('', $seen['session'], '超长会话 ID 必须丢弃（否则会被库截断成两个 ID）');
    });

    test('SSE 推送进程复用同一份白名单（裸 TCP 也要放行，否则浏览器报 ERR_FAILED 200）', function () use (
        $loadCorsConfig
    ): void {
        $loadCorsConfig('https://ok.example.com');

        same('https://ok.example.com', Cors::matchOrigin('https://ok.example.com'), '命中白名单应回显来源');
        same('', Cors::matchOrigin('https://evil.example.com'), '未命中返回空串，SSE 据此不下发放行头');
        same('', Cors::matchOrigin(''), '同源请求（无 Origin）本就不需要放行头');
    });

    // 还原环境变量，避免影响后续用例
    putenv('CORS_ORIGIN');
};
