<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use SimpleXMLElement;
use Webman\Http\Response;

/**
 * 统一响应出口：json / jsonp / xml / view。
 *
 * - 成功：HTTP 200 + body {code:0, message, data}
 * - 失败：HTTP 状态码 = 业务 code（仅当 4xx/5xx 时），body {code, message, data}
 * - 响应编码由 ResponseEncode 中间件写入 $request->encode，此处读取
 */
final class Result
{
    public const CODE_OK = 0;

    public static function ok(mixed $data = null, string $message = 'ok'): Response
    {
        return self::encode(['code' => self::CODE_OK, 'message' => $message, 'data' => $data], 200);
    }

    public static function fail(string $message, int $code = 1, mixed $data = null): Response
    {
        $payload = ['code' => $code, 'message' => $message, 'data' => $data];

        // 三个 ID 出错时一并返回，便于用户报障时直接提供（前端会把 action_id 拼进提示）：
        //   trace_id  = 本次请求；action_id = 本次操作；session_id = 本次会话。
        // 只在失败响应里加，成功响应保持原有信封不变。
        foreach (['trace_id' => 'traceId', 'action_id' => 'actionId', 'session_id' => 'sessionId'] as $key => $field) {
            $value = self::currentRequestValue($field);
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        return self::encode($payload, self::httpStatus($code));
    }

    public static function encode(array $payload, int $status = 200): Response
    {
        return match (self::currentEncode()) {
            'jsonp' => self::jsonp($payload, $status),
            'xml'   => self::xml($payload, $status),
            'view'  => self::view($payload),
            default => self::json($payload, $status),
        };
    }

    /** 业务 code 是否为标准 HTTP 状态码；是则作为 HTTP 状态，否则回 200。 */
    private static function httpStatus(int $code): int
    {
        return $code >= 400 && $code <= 599 ? $code : 200;
    }

    private static function currentEncode(): string
    {
        $request = request();
        return (string)($request->encode ?? 'json');
    }

    /** 当前请求上的某个 ID（traceId / actionId / sessionId）；CLI / 无请求上下文时为空串 */
    private static function currentRequestValue(string $field): string
    {
        if (!function_exists('request')) {
            return '';
        }

        return (string)(request()->{$field} ?? '');
    }

    private static function json(array $payload, int $status): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function jsonp(array $payload, int $status): Response
    {
        $callback = (string)request()->input('callback', 'callback');
        if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$.]*$/', $callback)) {
            $callback = 'callback';
        }
        $body = $callback . '(' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';
        return new Response($status, ['Content-Type' => 'text/javascript; charset=utf-8'], $body);
    }

    private static function xml(array $payload, int $status): Response
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><root/>');
        self::arrayToXml($payload, $xml);
        return new Response($status, ['Content-Type' => 'application/xml; charset=utf-8'], $xml->asXML());
    }

    private static function arrayToXml(array $data, SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $key = is_int($key) ? 'item' : $key;
            if (is_array($value)) {
                self::arrayToXml($value, $xml->addChild($key));
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }
        }
    }

    private static function view(array $payload): Response
    {
        $template = is_string($payload['template'] ?? null) ? $payload['template'] : '';
        $vars     = is_array($payload['vars'] ?? null) ? $payload['vars'] : [];
        unset($payload['template'], $payload['vars']);
        $vars['payload'] = $payload;
        return view($template, $vars);
    }
}
