<?php

declare(strict_types=1);

use plugin\cccms\support\OrphanChecker;

return static function (): void {
    suite('孤儿数据体检（OrphanChecker）');

    test('扫描结果结构完整：覆盖全部关联组，无问题时 issues 为空数组', function (): void {
        $result = OrphanChecker::scan();

        ok(isset($result['checked'], $result['issues']), '必须返回 checked / issues 两个键');
        ok(is_int($result['checked']), 'checked 必须是整数');
        ok($result['checked'] >= 15, '至少覆盖 14 组通用关联 + 3 个数据权限绑定维度');
        ok(is_array($result['issues']), 'issues 必须是数组');

        foreach ($result['issues'] as $issue) {
            ok(is_string($issue['key'] ?? null), '每条问题必须有 key');
            ok(is_string($issue['title'] ?? null), '每条问题必须有 title');
            ok(($issue['count'] ?? 0) > 0, '只有 count > 0 才会被列为问题');
            ok(is_array($issue['samples'] ?? null), 'samples 必须是数组');
            ok(count($issue['samples']) <= 5, '示例 id 最多 5 个');
        }
    }, true);

    test('示例 id 只取正整数（0 表示「不绑定」，不是孤儿）', function (): void {
        $result = OrphanChecker::scan(3);

        foreach ($result['issues'] as $issue) {
            foreach ($issue['samples'] as $id) {
                ok($id > 0, "示例 id 必须是正整数：{$issue['key']}");
            }
        }
    }, true);
};
