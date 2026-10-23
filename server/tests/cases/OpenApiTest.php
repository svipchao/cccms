<?php

declare(strict_types=1);

use plugin\cccms\support\Metrics;
use plugin\cccms\support\OpenApi;

/**
 * 接口文档（F1）与接口路径归一化（F4）。
 *
 * 这里的 ①③ 是**门禁性质**的断言：路由、权限注解、文档三者一旦不同步就失败，
 * 让 `php tests/run.php OpenApi` 能当 CI 里 openapi-check 的等价物。
 */
return static function (): void {
    suite('接口文档与路径归一化');

    // 测试运行器不经过 webman 的 HTTP 启动流程：直接 require 路由文件会因为在
    // `Route::$instance` 为 null 时调用 addRoute() 而致命错误，必须走 Route::load()。
    //
    // 必须加载**所有**插件：只加载 cccms 时，业务插件里声明了权限节点的方法会被
    // `OpenApi::check()` 误判成「未挂路由」。
    if (\Webman\Route::getRoutes() === []) {
        $routeDirs = [];
        foreach (glob(base_path() . '/plugin/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir . '/config/route.php')) {
                $routeDirs[] = $dir . '/config';
            }
        }
        \Webman\Route::load($routeDirs);
    }

    test('路由可枚举，且每条操作都有方法/路径/控制器', function (): void {
        $operations = OpenApi::operations();
        ok($operations !== [], '未枚举到任何路由：路由文件未加载或 Route::getRoutes() 为空');

        foreach ($operations as $op) {
            ok($op['method'] !== '' && $op['path'] !== '', '操作缺少 method 或 path');
            ok(str_contains($op['controller'], 'Controller'), '操作未指向控制器：' . $op['path']);
            ok($op['id'] === $op['method'] . ' ' . $op['path'], '操作 id 约定不符：' . $op['id']);
        }
    });

    test('路由与权限注解一致（文档门禁）', function (): void {
        $errors = OpenApi::check();
        ok($errors === [], "存在不一致：\n" . implode("\n", $errors));
    });

    test('OpenAPI 3.1 文档结构完整', function (): void {
        $spec = OpenApi::build('/api');
        ok($spec['openapi'] === '3.1.0', 'openapi 版本必须是 3.1.0');
        ok(isset($spec['paths']) && $spec['paths'] !== [], 'paths 不能为空');
        ok(isset($spec['components']['schemas']['Envelope']), '缺少统一信封 schema');
        ok(isset($spec['components']['securitySchemes']['bearerAuth']), '缺少 bearerAuth 安全方案');
        ok($spec['servers'][0]['url'] === '/api', 'servers 未按参数覆盖');

        // 公开接口不应要求鉴权：安全清单为空数组（而不是缺失字段）
        $operations = OpenApi::operations();
        foreach ($operations as $op) {
            $node = $spec['paths'][$op['path']][strtolower($op['method'])];
            ok(isset($node['security']), '操作缺少 security 字段：' . $op['id']);
            if ($op['noLogin'] || $op['noAuth']) {
                ok($node['security'] === [], '公开接口却要求鉴权：' . $op['id']);
            }
        }
    });

    test('分组树带插件归属（前端三层树：插件 → 分组 → 接口）', function (): void {
        $groups = OpenApi::tree();
        ok($groups !== [], '分组树不能为空');

        foreach ($groups as $group) {
            ok(($group['plugin'] ?? '') !== '', '分组缺少 plugin：' . ($group['key'] ?? '?'));
            ok(($group['pluginTitle'] ?? '') !== '', '分组缺少 pluginTitle：' . ($group['key'] ?? '?'));
            ok($group['operations'] !== [], '分组不应为空：' . $group['key']);
        }

        // 内置插件排最前（业务插件按插件名跟在后面）
        same('cccms', $groups[0]['plugin'], '内置插件 cccms 应排在最前');
        same(
            count($groups),
            count(array_unique(array_column($groups, 'key'))),
            '分组 key 不应重复（前端用它做节点 id）'
        );
    });

    test('参数类型从强转推断', function (): void {
        // 抽查一个已知签名：/log 的 page / limit 是 (int) 强转
        $detail = OpenApi::detail('GET /log');
        if ($detail === null) {
            return;
        }
        $types = [];
        foreach ($detail['params'] as $param) {
            $types[$param['name']] = $param['type'];
        }
        if (isset($types['page'])) {
            ok($types['page'] === 'integer', 'page 应推断为 integer，实际 ' . $types['page']);
        }
    });

    test('路径归一化（控 label 基数）', function (): void {
        ok(Metrics::normalizePath('/user') === '/user', '普通路径不应被改写');
        ok(Metrics::normalizePath('/user/123') === '/user/{n}', '数字段应归一化');
        ok(Metrics::normalizePath('/order/123/item/456') === '/order/{n}/item/{n}', '多段数字应全部归一化');
        ok(
            Metrics::normalizePath('/pay/9f8e7d6c5b4a39281706') === '/pay/{id}',
            '长 hex 段应归一化为 {id}'
        );
        ok(
            Metrics::normalizePath('/x/3f2504e0-4f89-11d3-9a0c-0305e82c3301') === '/x/{uuid}',
            'uuid 段应归一化为 {uuid}'
        );
        ok(Metrics::normalizePath('') === '(unknown)', '空路径应有兜底值');

        $long = '/' . str_repeat('a', 200);
        ok(mb_strlen(Metrics::normalizePath($long)) <= 81, '超长路径应被截断');
    });
};
