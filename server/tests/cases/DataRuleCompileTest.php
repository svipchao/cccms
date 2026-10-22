<?php

declare(strict_types=1);

use plugin\cccms\support\DataScope;

return static function (): void {
    suite('字段规则编译失败即拒绝（借鉴项）');

    test('合法规则编译成有序列表，字段与动作原样保留', function (): void {
        $compiled = DataScope::compileFieldRules([
            ['id' => 1, 'field' => 'phone', 'action' => 'mask'],
            ['id' => 2, 'field' => 'id_card', 'action' => 'hidden'],
            ['id' => 3, 'field' => 'remark', 'action' => 'readonly'],
        ]);

        same([
            ['field' => 'phone', 'action' => 'mask'],
            ['field' => 'id_card', 'action' => 'hidden'],
            ['field' => 'remark', 'action' => 'readonly'],
        ], $compiled, '顺序即执行顺序，不能折叠成「字段 => 动作」映射');
    });

    test('未知动作直接拒绝，而不是跳过该条', function (): void {
        $thrown = null;
        try {
            DataScope::compileFieldRules([
                ['id' => 7, 'field' => 'phone', 'action' => 'blur'],
            ]);
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        ok($thrown instanceof InvalidArgumentException, '未知动作必须抛异常');
        contains('#7', (string)$thrown?->getMessage(), '异常应带规则 id，便于定位');
    });

    test('字段名为空或不是合法列名同样拒绝', function (): void {
        foreach ([
            ['id' => 8, 'field' => '', 'action' => 'hidden'],
            ['id' => 9, 'field' => 'phone;drop', 'action' => 'hidden'],
        ] as $rule) {
            $thrown = null;
            try {
                DataScope::compileFieldRules([$rule]);
            } catch (InvalidArgumentException $e) {
                $thrown = $e;
            }

            ok($thrown instanceof InvalidArgumentException, "规则 #{$rule['id']} 应被拒绝");
        }
    });
};
