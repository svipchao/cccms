<?php

declare(strict_types=1);

use plugin\cccms\app\model\User;
use think\db\BaseQuery;

return static function (): void {
    suite('跨租户读取必须说明理由（借鉴项）');

    test('不填理由直接抛异常（避免成为默认选项）', function (): void {
        $thrown = null;
        try {
            User::withoutAllScopes('');
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        ok($thrown instanceof InvalidArgumentException, '空理由应被拒绝');
        contains('理由', (string)$thrown?->getMessage(), '异常消息应提示要写理由');
    });

    test('纯空白也视为未填写', function (): void {
        $thrown = null;
        try {
            User::withoutAllScopes("  \t\n");
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        ok($thrown instanceof InvalidArgumentException, '空白字符串不能绕过校验');
    });

    test('填了理由即可拿到完全绕过作用域的查询对象', function (): void {
        $query = User::withoutAllScopes('全局唯一键查重（测试）');

        ok($query instanceof BaseQuery, '应返回查询构造器');

        // `options['scope']` 缺省 = 没有任何全局作用域（租户与数据档位都解除）
        $options = $query->getOptions();
        ok(!isset($options['scope']), '跨租户查询不应再挂任何全局作用域');
    });
};
