<?php

declare(strict_types=1);

return static function (): void {
    suite('版本号单一来源（P2-1）');

    test('后端 config/app.php 定义了语义化版本', function (): void {
        $version = (string)config('app.version', '');

        ok($version !== '', 'config/app.php 必须有 version');
        ok(
            (bool)preg_match('/^\d+\.\d+\.\d+$/', $version),
            "version 应为 x.y.z 形式，实际是「{$version}」"
        );
    });

    test('后端版本与 frontend/package.json 一致', function (): void {
        $file = dirname(__DIR__, 3) . '/frontend/package.json';
        ok(is_file($file), "找不到 frontend/package.json：{$file}");

        $json = json_decode((string)file_get_contents($file), true);
        ok(is_array($json), 'frontend/package.json 解析失败');

        // 前端 package.json 是发布产物的版本来源，后端只做「同一份版本」的断言，
        // 不反向读取页面，避免把构建期信息带进运行时。
        same(
            (string)($json['version'] ?? ''),
            (string)config('app.version'),
            '前后端版本号不一致：改 config/app.php 或 frontend/package.json 时必须同步'
        );
    });
};
