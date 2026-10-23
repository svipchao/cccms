<?php

declare(strict_types=1);

use plugin\cccms\support\Slug;

return static function (): void {
    suite('权限 slug 归属口径（Slug）');

    test('belongsTo：等于节点或 node:action 都算属于', function (): void {
        ok(Slug::belongsTo('cccms:user', 'cccms:user'), '完全相等应命中');
        ok(Slug::belongsTo('cccms:user:index', 'cccms:user'), '按钮应归属其菜单');
        ok(Slug::belongsTo('cccms:user:index', ['cccms:role', 'cccms:user']), '数组里任一命中即可');
        ok(!Slug::belongsTo('cccms:username:x', 'cccms:user'), '不能按字符串前缀误伤（username 不是 user 的子节点）');
        ok(!Slug::belongsTo('', 'cccms:user'), '空节点不命中');
        ok(!Slug::belongsTo('cccms:user:index', ''), '空前缀不命中');
    });

    test('longestOwner：多个前缀命中时取最长（回收站优先挂 cccms:recycle 而不是 cccms）', function (): void {
        $nodes = ['cccms', 'cccms:recycle', 'cccms:crontab'];

        same('cccms:recycle', Slug::longestOwner('cccms:recycle:clean', $nodes), '应取最长前缀');
        same('cccms:crontab', Slug::longestOwner('cccms:crontab:run', $nodes), '应命中对应菜单');
        same('cccms:file', Slug::longestOwner('cccms:file:upload:chunk', ['cccms', 'cccms:file']), '多一级动作（分片上传）仍归到二级菜单');
        same(null, Slug::longestOwner('cccms:user:index', $nodes), '没有匹配前缀时返回 null');
        same(null, Slug::longestOwner('cccms', $nodes), '只有节点本身、没有动作段时不算归属（按钮不会是菜单本身）');
    });

    test('groupOf：机械取前两段，不足两段原样返回', function (): void {
        same('cccms:user', Slug::groupOf('cccms:user:index'), '三段取前两段');
        same('cccms:user', Slug::groupOf('cccms:user'), '两段原样');
        same('common', Slug::groupOf('common'), '一段原样');
    });

    test('actionOf：取最后一段，空串不报错', function (): void {
        same('index', Slug::actionOf('cccms:user:index'), '取动作名');
        same('user', Slug::actionOf('cccms:user'), '两段时等于末段');
        same('', Slug::actionOf(''), '空串返回空串');
    });
};
