<?php

declare(strict_types=1);

use plugin\cccms\support\DataScope;
use plugin\cccms\support\TenantContext;

return static function (): void {
    suite('定时任务执行身份（P1-2）');

    test('runAs 期间租户身份被覆盖，结束后恢复', function (): void {
        same(null, TenantContext::currentTenantId(), 'CLI 下本应无租户上下文');

        $inside = TenantContext::runAs(7, static fn (): ?int => TenantContext::currentTenantId());
        same(7, $inside, 'runAs 期间应按指定租户');

        same(null, TenantContext::currentTenantId(), '执行完必须恢复原身份（常驻进程不能泄漏）');
    });

    test('平台身份（0）与「无上下文」（null）可区分', function (): void {
        $inside = TenantContext::runAs(TenantContext::PLATFORM_ID, static fn (): ?int => TenantContext::currentTenantId());
        same(0, $inside, '平台身份应覆盖为 0，而不是回落成 null');

        same(true, TenantContext::runAs(0, static fn (): bool => TenantContext::isPlatform()));
        same(false, TenantContext::runAs(7, static fn (): bool => TenantContext::isPlatform()));
    });

    test('嵌套 runAs 逐层恢复', function (): void {
        TenantContext::runAs(1, static function (): void {
            TenantContext::runAs(2, static function (): void {
                same(2, TenantContext::currentTenantId());
            });
            same(1, TenantContext::currentTenantId(), '内层退出后应回到外层身份');
        });

        same(null, TenantContext::currentTenantId());
    });

    test('任务抛异常也必须恢复身份（try/finally）', function (): void {
        try {
            TenantContext::runAs(9, static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // 预期
        }

        same(null, TenantContext::currentTenantId(), '异常路径同样要恢复');
    });

    test('DataScope::runAs 无请求上下文时直接执行（CLI / 调度进程）', function (): void {
        $hit = 0;
        $result = DataScope::runAs(null, static function () use (&$hit): string {
            $hit++;

            return 'ok';
        });

        same('ok', $result);
        same(1, $hit);
        same(null, DataScope::currentUser(), 'CLI 下不应凭空出现用户上下文');
    });
};
