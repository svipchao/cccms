<?php

declare(strict_types=1);

use plugin\cccms\support\SecurityBootstrap;

return static function (): void {
    suite('启动期安全自检（SecurityBootstrap）');

    /** 临时改环境变量，执行完恢复（同一进程里其它用例还要用） */
    $withEnv = static function (array $vars, callable $fn): void {
        $backup = [];
        foreach ($vars as $key => $value) {
            $backup[$key] = getenv($key);
            if ($value === null) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        try {
            $fn();
        } finally {
            foreach ($backup as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    };

    /** 断言自检抛错且提示命中关键字 */
    $expectReject = static function (string $keyword, callable $fn): void {
        try {
            $fn();
            fail("应当拒绝启动，但没有抛异常（期望提示包含：{$keyword}）");
        } catch (RuntimeException $e) {
            contains($keyword, $e->getMessage(), '拒绝原因应指向具体配置项');
        }
    };

    $secret = str_repeat('a1b2c3d4', 8); // 64 字节，满足 HS256 下限

    test('JWT_SECRET 未配置：拒绝启动', function () use ($withEnv, $expectReject, $secret): void {
        $withEnv(['JWT_SECRET' => null], function () use ($expectReject): void {
            $expectReject('JWT_SECRET 未配置', static fn () => SecurityBootstrap::check(true));
        });
    });

    test('JWT_SECRET 过短：拒绝启动（HS256 弱密钥可被暴力枚举）', function () use ($withEnv, $expectReject): void {
        $withEnv(['JWT_SECRET' => 'too-short'], function () use ($expectReject): void {
            $expectReject('长度不足', static fn () => SecurityBootstrap::check(true));
        });
    });

    test('JWT_SECRET 等于仓库公开的历史默认值：拒绝启动', function () use ($withEnv, $expectReject): void {
        $forbidden = 'cccms-local-dev-secret-0123456789abcdef-0123456789abcdef-0123456789abcdef';
        $withEnv(['JWT_SECRET' => $forbidden], function () use ($expectReject): void {
            $expectReject('历史默认值', static fn () => SecurityBootstrap::check(true));
        });
    });

    test('开发布局：密钥合规即可启动，DATA_ENCRYPT_KEY 缺失只告警不终止', function () use ($withEnv, $secret): void {
        $withEnv(
            ['JWT_SECRET' => $secret, 'DATA_ENCRYPT_KEY' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null],
            function (): void {
                SecurityBootstrap::check(true);
                ok(true, '开发布局下不应因为本地缺省配置而拒绝启动');
            }
        );
    });

    test('生产环境：缺 DB 凭据 / DEBUG / CORS 通配 / 缺审计密钥都要拒绝', function () use ($withEnv, $expectReject, $secret): void {
        $base = [
            'JWT_SECRET'      => $secret,
            'DATA_ENCRYPT_KEY' => $secret,
            'DB_USERNAME'     => 'cccms',
            'DB_PASSWORD'     => 'strong-pass',
            'APP_DEBUG'       => 'false',
            'CORS_ORIGIN'     => 'https://example.com',
            'AUDIT_SIGNING_KEY' => 'audit-key',
        ];

        // 逐项破坏，确认每一道闸都真的会拦
        $cases = [
            'DB_USERNAME / DB_PASSWORD' => ['DB_USERNAME' => null, 'DB_PASSWORD' => null],
            'APP_DEBUG'                 => ['APP_DEBUG' => 'true'],
            'CORS_ORIGIN'               => ['CORS_ORIGIN' => ' * '],
            'AUDIT_SIGNING_KEY'         => ['AUDIT_SIGNING_KEY' => ''],
        ];

        foreach ($cases as $keyword => $broken) {
            $withEnv(array_merge($base, $broken), function () use ($expectReject, $keyword): void {
                $expectReject($keyword, static fn () => SecurityBootstrap::check(false));
            });
        }

        // 全部合规 → 放行
        $withEnv($base, function (): void {
            SecurityBootstrap::check(false);
            ok(true, '生产配置完整时应正常启动');
        });
    });
};
