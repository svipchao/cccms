<?php

declare(strict_types=1);

use plugin\cccms\support\OpenApi;
use plugin\cccms\support\SchemaMeta;

/**
 * 接口文档的枚举值与返回字段（自动取自库表结构）。
 *
 * 覆盖三件事：
 *   ① 枚举值来自 `CHECK` 约束、标签来自列注释（含 MySQL 把字符串字面量写成
 *      `_utf8mb4\'cron\'` 的坑，不能把 introducer 里的数字当成枚举值）；
 *   ② 整包透传的接口能顺着 Logic 补出参数，并带上枚举；
 *   ③ 只有 GET 推断返回字段，且不列出敏感列。
 *
 * 需要真实 MySQL（元数据读 information_schema）。
 */
return static function (): void {
    suite('接口文档的枚举值与返回字段');

    // 测试运行器不经过 webman HTTP 启动流程，路由需要显式加载（见 OpenApiTest 说明）。
    // 加载**所有**插件：只加载 cccms 会让业务插件的接口缺失，进而影响按全量接口断言的用例。
    if (\Webman\Route::getRoutes() === []) {
        $routeDirs = [];
        foreach (glob(base_path() . '/plugin/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir . '/config/route.php')) {
                $routeDirs[] = $dir . '/config';
            }
        }
        \Webman\Route::load($routeDirs);
    }

    test('枚举值取自 CHECK 约束，标签取自列注释', function (): void {
        $type = SchemaMeta::table('sys_menu')['type'] ?? null;
        ok($type !== null, 'sys_menu.type 元数据缺失（数据库不可用？）');
        same([1, 2, 3], array_column($type['enum'], 'value'), 'sys_menu.type 值域应为 1/2/3');
        same('目录', $type['enum'][0]['label'] ?? '', '值 1 的标签应取列注释里的「目录」');

        $source = SchemaMeta::table('sys_crontab_log')['source'] ?? null;
        ok($source !== null, 'sys_crontab_log.source 元数据缺失');
        same(
            ['cron', 'retry', 'manual', 'timeout'],
            array_column($source['enum'], 'value'),
            '字符串枚举应正确解析（不能把 _utf8mb4 里的 8 / 4 当成值）'
        );
    }, true);

    test('跨表口径不一致的列不给出统一枚举', function (): void {
        same([], SchemaMeta::sharedEnum('status'), 'status 各表值域不同（0/1 vs 0-4），不应写死一套');
        ok(SchemaMeta::sharedEnum('keep_alive') !== [], '各表一致的列仍应给出枚举');
    }, true);

    test('整包透传的接口从 Logic 补出参数并带枚举', function (): void {
        $detail = OpenApi::detail('GET /crontab/logs');
        ok($detail !== null, '缺少 GET /crontab/logs');

        $params = [];
        foreach ($detail['params'] as $param) {
            $params[$param['name']] = $param;
        }

        ok(isset($params['crontab_id']), '应从 Logic 补出 crontab_id');
        ok(isset($params['page'], $params['limit']), 'PageInput::of() 隐含 page / limit');
        same('integer', $params['page']['type'], 'page 应推断为 integer');
        ok(isset($params['status']['enum']), 'status 应带枚举');
        same([0, 1, 2, 3], array_column($params['status']['enum'], 'value'), 'status 值域应为 0-3');
    }, true);

    test('GET 按主表推断返回字段，且不含敏感列', function (): void {
        $detail = OpenApi::detail('GET /user');
        ok($detail !== null, '缺少 GET /user');
        same('sys_user', $detail['table'], 'GET /user 的主表应为 sys_user');
        ok($detail['response']['inferred'] === true, 'response.inferred 应为 true');

        $names = array_column($detail['response']['fields'], 'name');
        ok(in_array('username', $names, true), '返回字段应含 username');
        ok(!in_array('password', $names, true), '返回字段不应包含口令列');

        // 写接口返回 id / null，列整表字段会误导
        $save = OpenApi::detail('POST /user/save');
        if ($save !== null) {
            same([], $save['response']['fields'], 'POST 不应推断返回字段');
        }
    }, true);

    test('OpenAPI spec 写出 enum 与 Table schema', function (): void {
        $spec = OpenApi::build('/api');

        $status = null;
        foreach ($spec['paths']['/crontab/logs']['get']['parameters'] ?? [] as $param) {
            if ($param['name'] === 'status') {
                $status = $param;
            }
        }
        ok($status !== null, 'spec 缺少 status 参数');
        same([0, 1, 2, 3], $status['schema']['enum'], '参数 schema 应带 enum');
        ok(isset($status['schema']['x-enum-labels']), '枚举标签应写在 x-enum-labels');

        ok(isset($spec['components']['schemas']['Table.sys_crontab_log']), '缺少被引用的表结构 schema');
        ok(!isset($spec['components']['schemas']['Table.sys_user_role']), '没被引用的表不应注册进 spec');
    }, true);
};
