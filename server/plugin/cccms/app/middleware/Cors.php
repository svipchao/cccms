<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 跨域处理 + 请求标识（trace / action / session）。
 *
 * 这里是中间件链的**第一环**，三个 ID 都在这里落到 `$request` 上，后续中间件
 * （OperationLog）与控制器直接读：
 *   - `traceId`：**一次 HTTP 请求**，后端生成，回写响应头 `X-Trace-Id`；
 *   - `actionId`：**一次用户操作**（进页面 / 点保存），由前端生成并经 `X-Action-Id` 传入，
 *     一次操作的多个请求共用它，日志页的「链路视图」按它聚合；
 *   - `sessionId`：**一次页面会话**（刷新即换），由前端生成并经 `X-Session-Id` 传入，
 *     用于审计视角的「会话轨迹」。
 *
 * 为什么 action / session 由前端给：只有前端知道"一次操作"从哪开始 —— 后端若按请求生成，
 * 一个请求只有一条日志，"链路"永远只有一行；若按时间窗口猜，又会把连续操作并成一条。
 *
 * 传入值必须通过格式与长度校验（与日志表 32 字节一致），不合法一律丢弃：
 * 避免把任意文本写进日志 / 响应头。跨域下前端要发这两个自定义头，必须出现在
 * `Access-Control-Allow-Headers` 里。
 *
 * 跨域来源取 `plugin.cccms.cors.origin` 白名单（`.env` 的 `CORS_ORIGIN`，逗号分隔）：
 * 命中才回显具体 Origin，未命中**一个 CORS 头都不下发**，由浏览器自行拦截。
 * 默认白名单为空 = 只允许同源（见 `config/cors.php` 的说明）。
 */
class Cors implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $traceId = self::traceId();
        $request->traceId = $traceId;
        $request->actionId = self::correlationId($request->header('X-Action-Id', ''));
        $request->sessionId = self::correlationId($request->header('X-Session-Id', ''));

        $origin = self::allowedOrigin($request);

        if ($request->method() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $handler($request);
        }

        // 只有「带 Origin 且命中白名单」才下发 CORS 放行头：
        // 未命中时连 Allow-Methods / Allow-Headers 都不给，避免向未授权来源泄露接口形状；
        // 同源请求浏览器不做 CORS 校验，给了也没意义。
        $headers = ['X-Trace-Id' => $traceId];

        if ($origin !== '') {
            $headers += [
                'Access-Control-Allow-Origin'      => $origin,
                'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
                // X-Trace-Id 保留给旧前端（已不参与生成，仅避免预检失败）
                'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-Requested-With, X-Trace-Id, X-Action-Id, X-Session-Id',
                'Access-Control-Allow-Credentials' => 'false',
                'Access-Control-Max-Age'           => '86400',
                // 跨域下响应头默认不可读：滑动续期的 X-Refresh-Token 必须在此声明，
                // 否则分域部署时前端读不到续期令牌
                'Access-Control-Expose-Headers'    => 'X-Trace-Id, X-Refresh-Token',
            ];
        }

        // 带 Origin 的响应必须声明 Vary，否则中间缓存可能把「未放行来源」的响应
        // 复用给「已放行来源」。无 Origin（同源请求）时无需声明。
        if ((string)$request->header('Origin', '') !== '') {
            $headers['Vary'] = 'Origin';
        }

        return $response->withHeaders($headers);
    }

    /**
     * 命中白名单则返回原样回显的 Origin；未命中返回空串。
     *
     * 不返回 `*`：回显具体来源才能与 `Vary: Origin` 配合，也便于审计到底放行了谁。
     */
    private static function allowedOrigin(Request $request): string
    {
        return self::matchOrigin((string)$request->header('Origin', ''));
    }

    /**
     * 白名单匹配（不依赖 `Webman\Http\Request`，供非 HTTP 中间件场景复用）。
     *
     * SSE 推送进程是裸 TCP 自己写响应头（`process/Sse.php`），拿不到中间件链，
     * 但它**照样要跨域**：开发前端在 `5173`、推送端口是 `8788`，浏览器对
     * `EventSource` 同样执行 CORS 校验 —— 少一个 `Access-Control-Allow-Origin`
     * 就会得到 `net::ERR_FAILED 200 (OK)`（curl 看不出问题）。白名单只有这一处，
     * 两条链路认的是同一份 `.env` 的 `CORS_ORIGIN`。
     */
    public static function matchOrigin(string $origin): string
    {
        if ($origin === '') {
            return '';
        }

        $allow = (string)config('plugin.cccms.cors.origin', '');
        if ($allow === '') {
            return '';
        }

        foreach (explode(',', $allow) as $item) {
            $item = trim($item);
            // Origin 按 RFC 6454 大小写不敏感，用 strcasecmp 比较
            if ($item !== '' && strcasecmp($item, $origin) === 0) {
                return $origin;
            }
        }

        return '';
    }

    /**
     * 本次请求的 ID：形如 `260918143012-a1b2c3d4e5f6`。
     *
     * 时间前缀便于排序，随机段避免碰撞；**不接受客户端传入** —— 请求级 ID
     * 由服务端掌握，才能保证每条日志都有、且不会被伪造。
     */
    private static function traceId(): string
    {
        return date('ymdHis') . '-' . bin2hex(random_bytes(6));
    }

    /**
     * 校验前端传来的关联 ID（action / session）。
     *
     * 只允许字母/数字与 `.` `_` `-`，且不超过 32 字节（与日志表列宽一致）：
     * 既挡住头注入，也避免写入时被截断成两个不同的 ID。不合法返回空串，
     * 该条日志就没有这个关联信息（不影响日志本身写入）。
     */
    private static function correlationId(mixed $raw): string
    {
        $value = trim((string)$raw);

        return preg_match('/^[A-Za-z0-9._-]{1,32}$/', $value) === 1 ? $value : '';
    }
}
