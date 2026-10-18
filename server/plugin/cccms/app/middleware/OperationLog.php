<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\ClientIp;
use plugin\cccms\support\LogChain;
use plugin\cccms\support\LogRedactor;
use plugin\cccms\support\PermissionMeta;
use plugin\cccms\support\SysConfig;
use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 操作日志：写操作与失败请求落库 sys_log。
 *
 * 落库内容分三类，便于事后回放：
 *   1. 语义化定位：`node`（权限节点 slug）+ `title`（注解里的操作名）。
 *      只看 `/dict/save` 无法判断业务含义，`cccms:dict:save` + 「新增字典类型」才可读。
 *   2. 请求与响应：`params`（query + body，递归脱敏，含上传文件名）+ `result`（响应体）。
 *   3. 环境信息：`ip` / `ua` / `method` / `path` / `cost` / `status_code`。
 */
class OperationLog implements MiddlewareInterface
{
    private const WRITE_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * 不写日志的路径：探活 / 指标抓取。
     *
     * 这类请求由机器按固定周期发起（容器 `HEALTHCHECK` 每 30s、Prometheus 每 15~60s），
     * 写进 `sys_log` 有两个坏处：
     *   1. 真实业务日志被周期请求淹没，按时间翻日志变得没有意义；
     *   2. `/metrics` 的请求量**本身就是从 `sys_log` 派生的**，抓取记录会自反馈成
     *      「请求量虚高」，越抓越高。
     * 慢接口告警同样跳过：探针变慢本身就意味着依赖故障，告警会随故障持续刷屏。
     */
    private const SKIP_PATHS = ['/ping', '/healthz', '/metrics'];

    /** text 上限 65535 字节，utf8mb4 单字符最多 4 字节，8000 字符足够安全 */
    private const TEXT_LIMIT = 8000;

    public function process(Request $request, callable $handler): Response
    {
        $start = microtime(true);

        try {
            $response = $handler($request);
            $cost = $this->cost($start);
            $this->alertSlow($request, $cost);
            $this->write($request, $response, null, $cost);

            return $response;
        } catch (Throwable $e) {
            $cost = $this->cost($start);
            $this->alertSlow($request, $cost);
            $this->write($request, null, $e, $cost);
            throw $e;
        }
    }

    private function cost(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }

    /**
     * 慢接口告警：耗时超过阈值时写一条独立 channel（slow）的告警日志。
     *
     * 阈值来自 `log.slow_threshold`（毫秒，0 = 关闭）。覆盖**所有**请求（含 GET），
     * 不依赖「是否写操作日志」—— 慢查询同样值得盯；告警失败绝不影响业务。
     */
    private function alertSlow(Request $request, int $cost): void
    {
        if (self::isProbe($request)) {
            return;
        }

        $threshold = SysConfig::getInt('log.slow_threshold', 0);
        if ($threshold <= 0 || $cost < $threshold) {
            return;
        }

        try {
            Log::channel('slow')->warning(sprintf(
                '%s %s cost=%dms user=%s ip=%s trace=%s',
                $request->method(),
                '/' . ltrim($request->path(), '/'),
                $cost,
                (string)($request->user?->username ?? '-'),
                (ClientIp::resolve($request) ?: '-'),
                (string)($request->traceId ?? '-')
            ));
        } catch (Throwable) {
            // 告警失败不影响业务
        }
    }

    private function write(Request $request, ?Response $response, ?Throwable $error, int $cost): void
    {
        // 探活 / 指标抓取是周期性的机器请求，不入业务日志（见 SKIP_PATHS 说明）
        if (self::isProbe($request)) {
            return;
        }

        // 登录接口由 AuthLogic 自己记（path='/auth/login'），这里跳过：那时没有用户上下文，
        // 中间件记出来的 user_id=0 且 node/title 为空，是重复且不友好的记录
        if ($request->method() === 'POST' && '/' . ltrim($request->path(), '/') === '/auth/login') {
            return;
        }

        // 失败状态码在「是否记录」之前算出来：读请求默认不记，但**失败必须记**
        $statusCode = $error !== null
            ? ($error instanceof ApiException ? (int)$error->getCode() : 500)
            : ($response?->getStatusCode() ?? 200);
        $failed = $error !== null || $statusCode >= 400;

        // 写操作必记；读操作默认不记，可由 log.record_read 打开（日志量会明显增加）。
        // 但**已登录用户的失败请求一律记录**：报障大多来自 GET（列表 / 详情 / 导出），
        // 不记的话按 trace_id 打开链路视图是空的 —— 功能看着有、实际用不了。
        // 匿名请求（登录失败、扫描器 404 等）仍不记，避免把攻击流量写进审计表。
        if (!in_array($request->method(), self::WRITE_METHODS, true)
            && !SysConfig::getBool('log.record_read', false)
            && !($failed && $request->user !== null)) {
            return;
        }

        try {
            $user = $request->user;

            // 路径语义化：控制器 + 方法 → 权限节点 slug 与注解标题
            $meta = PermissionMeta::of((string)$request->controller, (string)$request->action);

            // query + body 合并，保留完整入参（同名时以 body 为准）
            $params = array_merge($request->get(), $request->post());
            // 脱敏规则抽到 LogRedactor，归档冷数据时复用同一份（避免两处规则漂移）
            $params = LogRedactor::redact($params);

            // 上传接口的 body 是空的，补上原始文件名，否则日志看不出传了什么
            $files = self::fileNames($request);
            if ($files) {
                $params['_files'] = $files;
            }

            $result = $error !== null ? $error->getMessage() : (string)$response?->rawBody();

            // 统一走 LogChain：它一边算链式哈希（防篡改），一边负责这一次插入。
            // 直接 Db::name('log')->insert() 会绕过链，留下无法校验的"空洞"。
            LogChain::write([
                'user_id'     => $user?->id ?? 0,
                'username'    => $user?->username ?? '',
                // 与链路视图的 failed 口径保持一致：异常或 4xx/5xx 都算失败
                'status'      => $failed ? 0 : 1,
                'message'     => $error !== null ? self::clip($error->getMessage(), 255) : '',
                'method'      => $request->method(),
                'path'        => '/' . ltrim($request->path(), '/'),
                'node'        => (string)($meta['slug'] ?? ''),
                'title'       => (string)($meta['title'] ?? ''),
                // 三个 ID 分工：trace = 一次请求；action = 一次操作（多个请求）；
                // session = 一次页面会话（刷新即换）。均由 Cors（第一环）落到 $request。
                'trace_id'    => (string)($request->traceId ?? ''),
                'action_id'   => (string)($request->actionId ?? ''),
                'session_id'  => (string)($request->sessionId ?? ''),
                // 可信代理 + 取最右非可信 IP；不能用 getRealIp()（反代下取 XFF 首值，可伪造）
                'ip'          => ClientIp::resolve($request),
                'ua'          => self::clip((string)$request->header('User-Agent', ''), 255),
                'params'      => self::encode($params),
                'result'      => self::clip($result, self::TEXT_LIMIT),
                'status_code' => $statusCode,
                'cost'        => $cost,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // 日志写入失败绝不影响业务
            Log::error('operation log failed: ' . $e->getMessage());
        }
    }

    /** 是否为「探活 / 指标」这类不该入日志的周期性请求 */
    private static function isProbe(Request $request): bool
    {
        return in_array('/' . ltrim($request->path(), '/'), self::SKIP_PATHS, true);
    }

    /**
     * 收集上传文件名（形如 `file:报告.pdf`）。
     *
     * @return string[]
     */
    private static function fileNames(Request $request): array
    {
        $out = [];

        try {
            foreach ((array)$request->file() as $field => $item) {
                foreach (is_array($item) ? $item : [$item] as $file) {
                    if (is_object($file) && method_exists($file, 'getUploadName')) {
                        $out[] = $field . ':' . $file->getUploadName();
                    }
                }
            }
        } catch (Throwable) {
            // 取文件名失败不影响日志主体
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        return self::clip(
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::TEXT_LIMIT
        );
    }

    private static function clip(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }
}
