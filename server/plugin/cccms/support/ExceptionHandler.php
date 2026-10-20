<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use Throwable;
use Webman\Exception\ExceptionHandlerInterface;
use Webman\Http\Request;
use Webman\Http\Response;

/** 统一异常处理：ApiException → Result 结构；其它异常 → 500（debug 下透出详情）。 */
final class ExceptionHandler implements ExceptionHandlerInterface
{
    public function report(Throwable $exception)
    {
        Log::error((string)$exception);
    }

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof ApiException) {
            return Result::fail($exception->getMessage(), $exception->getCode(), $exception->getData());
        }

        // 数据库层异常（SQL、表结构、连接信息）一律不回显细节：
        // 即便误开了 APP_DEBUG，也不能把 SQLSTATE / SQL 语句透给前端。
        if ($this->isDatabaseException($exception)) {
            return Result::fail('服务器内部错误', 500);
        }

        $message = config('app.debug', false) ? $exception->getMessage() : '服务器内部错误';
        return Result::fail($message, 500);
    }

    /** 是否为数据库异常（think-orm 包装后的异常仍是 PDOException 子类，消息里带 SQLSTATE） */
    private function isDatabaseException(Throwable $exception): bool
    {
        return $exception instanceof \PDOException
            || str_contains($exception->getMessage(), 'SQLSTATE[');
    }
}
