<?php

declare(strict_types=1);

use plugin\cccms\app\logic\MenuLogic;

/**
 * 「仅本地开发环境可见」的菜单节点过滤（`MenuLogic::DEV_ONLY_PREFIXES`）。
 *
 * 线上是「已构建」形态（仓库根没有 `frontend/package.json`），在测试进程里伪造不出来，
 * 所以这里只覆盖过滤所依赖的前缀判定：`cccms:apidoc` 与 perm-scan 生成的按钮节点必须命中，
 * 前缀相近但不同的 slug 不能误伤（误伤会让线上好用的菜单凭空消失）。
 */
return static function (): void {
    suite('仅开发环境可见的菜单');

    // 私有方法按类作用域绑定后调用，避免为测试放开可见性
    $isDevOnly = Closure::bind(
        static fn (string $node): bool => MenuLogic::isDevOnly(['node' => $node]),
        null,
        MenuLogic::class
    );

    test('接口文档菜单与其按钮节点都算开发环境专属', function () use ($isDevOnly): void {
        ok($isDevOnly('cccms:apidoc'), 'cccms:apidoc 应被过滤');
        ok($isDevOnly('cccms:apidoc:index'), 'cccms:apidoc:index 应被过滤');
        ok($isDevOnly('cccms:apidoc:spec'), 'cccms:apidoc:spec 应被过滤');
    });

    test('前缀相近的其它菜单不被误伤', function () use ($isDevOnly): void {
        ok(!$isDevOnly('cccms:apidocx'), '不是以冒号分隔的前缀，不算命中');
        ok(!$isDevOnly('cccms:monitor'), '接口监控不是开发环境专属');
        ok(!$isDevOnly(''), '空 slug 不应命中');
    });
};
