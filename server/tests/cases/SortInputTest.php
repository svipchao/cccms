<?php

declare(strict_types=1);

use plugin\cccms\support\SortInput;

return static function (): void {
    suite('SortInput（列头排序白名单）');

    test('命中白名单：前端 prop 映射到真实列', function (): void {
        $sortable = ['create_time' => 'create_time', 'action_type' => 'action'];

        same(['create_time', 'asc'], SortInput::resolve(['sort' => 'create_time', 'order' => 'asc'], $sortable));
        same(['action', 'desc'], SortInput::resolve(['sort' => 'action_type', 'order' => 'desc'], $sortable));
    });

    test('方向缺省时默认 desc，大小写不敏感', function (): void {
        $sortable = ['id' => 'id'];

        same(['id', 'desc'], SortInput::resolve(['sort' => 'id'], $sortable));
        same(['id', 'asc'], SortInput::resolve(['sort' => 'id', 'order' => 'ASC'], $sortable));
        same(['id', 'desc'], SortInput::resolve(['sort' => 'id', 'order' => 'sideways'], $sortable));
    });

    test('未指定 / 未知列回落页面默认排序', function (): void {
        $sortable = ['id' => 'id'];
        $default  = ['sort', 'asc'];

        same($default, SortInput::resolve([], $sortable, 'sort', 'asc'));
        same($default, SortInput::resolve(['sort' => 'nickname', 'order' => 'asc'], $sortable, 'sort', 'asc'));
        same($default, SortInput::resolve(['sort' => '', 'order' => 'asc'], $sortable, 'sort', 'asc'));
    });

    test('计算列 / 合成列不在白名单时一律回落默认', function (): void {
        // 例如 tenant 的 user_count、dict 的 category_name：表里没有这些列
        same(['id', 'desc'], SortInput::resolve(['sort' => 'user_count'], ['id' => 'id']));
        same(['id', 'desc'], SortInput::resolve(['sort' => 'category_name'], ['id' => 'id']));
    });

    test('注入串不会被带进排序字段', function (): void {
        $sortable = ['id' => 'id'];

        foreach (['id; drop table user', 'id desc, (select 1)', 'id`', "id'"] as $payload) {
            same(['id', 'desc'], SortInput::resolve(['sort' => $payload, 'order' => 'desc'], $sortable), '注入串应回落默认排序');
        }
    });

    test('非字符串参数（数组 / null）不报错且回落默认', function (): void {
        same(['id', 'desc'], SortInput::resolve(['sort' => ['id'], 'order' => ['asc']], ['id' => 'id']));
        same(['id', 'desc'], SortInput::resolve(['sort' => null, 'order' => null], ['id' => 'id']));
    });
};
