<?php

declare(strict_types=1);

use plugin\cccms\support\Environment;

return static function (): void {
    suite('危险功能统一门禁（P2-8）');

    test('注册表登记了在线升级与代码生成器', function (): void {
        ok(isset(Environment::DANGEROUS['upgrade']), 'upgrade 必须登记在 Environment::DANGEROUS');
        ok(isset(Environment::DANGEROUS['generator']), 'generator 必须登记在 Environment::DANGEROUS');
    });

    test('未登记的功能名被拒绝（不静默放行）', function (): void {
        try {
            Environment::blockedBy('unregistered_feature');
            fail('未登记的危险功能必须抛 InvalidArgumentException');
        } catch (InvalidArgumentException) {
            ok(true);
        }
    });

    test('blockedBy 只返回三种状态，且与目录自检一致', function (): void {
        $blocked = Environment::blockedBy('upgrade');
        ok(in_array($blocked, ['', 'environment', 'switch'], true), "非法返回值：{$blocked}");

        // 部署形态（没有 frontend/package.json）下必须是硬拦截，开关打开也不放行
        if (!Environment::isDevLayout()) {
            same('environment', $blocked, '非开发布局必须报环境闸');
            same(false, Environment::featureEnabled('upgrade'), '非开发布局不能让危险功能可用');
        }
    });
};
