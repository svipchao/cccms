<?php

declare(strict_types=1);

use plugin\cccms\support\LogChain;
use plugin\cccms\support\LogVerifier;
use think\facade\Db;

/**
 * P2-14：审计日志链式哈希。
 *
 * 覆盖三件事：
 *   1. **写法本身**：`normalize()` 的取值口径（固定顺序 / 类型 / 字符截断）与 `hash()` 的确定性；
 *   2. **验证器能定位篡改**（核心验收项）：改内容 → `content_modified`；删中间行 → `link_broken`；
 *      链尾被改 → `tail_stale`；
 *   3. **不误报**：未入链的历史行只统计不报错，校验起点的前驱缺失不算断点。
 *
 * 需要真实 MySQL；不可用时跳过（CI 设 `CCCMS_TEST_REQUIRE_SERVICES=1` 时跳过即失败）。
 */
return static function (): void {
    suite('审计日志链式哈希（P2-14）');

    /** 用例自己写的行统统打上这个标记，便于精确清理 */
    $marker = '__itest__chain__';
    $ids    = [];

    $cleanup = static function () use (&$ids, $marker): void {
        if ($ids !== []) {
            Db::name('log')->whereIn('id', $ids)->delete();
            $ids = [];
        }
        Db::name('log')->where('username', $marker)->delete();
        // 删完后把校验起点前移，避免本用例的清理动作影响后续校验
        LogChain::reanchor();
    };

    /** 用同样的字段形状写一条链上记录，返回其 id */
    $write = static function (string $message) use (&$ids, $marker): int {
        $id = LogChain::write([
            'user_id'     => 0,
            'username'    => $marker,
            'method'      => 'POST',
            'path'        => '/__itest__/chain',
            'node'        => '',
            'title'       => '链式哈希用例',
            'status'      => 1,
            'message'     => $message,
            'trace_id'    => 'itest-chain',
            'ip'          => '127.0.0.1',
            'ua'          => 'phpunit',
            'params'      => '{}',
            'result'      => 'ok',
            'status_code' => 200,
            'cost'        => 1,
        ]);
        $ids[] = $id;

        return $id;
    };

    $row = static fn (int $id): array => (array)Db::name('log')->where('id', $id)->find();

    test('normalize：固定顺序 / 类型 / 截断，写入与校验两侧口径一致', function () use ($cleanup): void {
        try {
            $normalized = LogChain::normalize([
                'user_id' => '7',
                'status' => '0',
                'cost' => 12.9,
                'params' => null,
                'create_time' => '2026-01-02 03:04:05',
                'username' => str_repeat('长', 100),   // 超过 varchar(64)
            ]);

            // 顺序固定（数组顺序即哈希顺序）；当前版本含 action_id / session_id
            same(
                [
                    'user_id', 'username', 'method', 'path', 'node', 'title',
                    'status', 'message', 'trace_id', 'action_id', 'session_id',
                    'ip', 'ua', 'params', 'result', 'status_code', 'cost', 'create_time',
                ],
                array_keys($normalized),
                '字段顺序必须固定'
            );

            same(7, $normalized['user_id'], '数值列按 int 取值');
            same(0, $normalized['status'], '字符串 "0" 也是 int 0');
            same(12, $normalized['cost'], '浮点截断为 int');
            same('', $normalized['params'], 'null 归一为空串');
            same(64, mb_strlen($normalized['username']), '按**字符**截断到列宽（utf8mb4 的 varchar 单位是字符）');

            // 缺 create_time 时补当前时间（与列的 DEFAULT 语义一致，避免写入值与哈希值不同源）
            $filled = LogChain::normalize([]);
            ok($filled['create_time'] !== '', 'create_time 缺失时应补当前时间');
            ok($filled['status_code'] === 200 && $filled['status'] === 1, '默认值按列默认走');

            same(
                LogChain::hash('p', $normalized),
                LogChain::hash('p', $normalized),
                '同一输入必须得到同一哈希'
            );
            ok(LogChain::hash('p1', $normalized) !== LogChain::hash('p2', $normalized), 'prev_hash 变化必须改变结果');
        } finally {
            $cleanup();
        }
    });

    test('写入后链自洽；改过任意一行即被定位', function () use ($write, $row, $cleanup): void {
        try {
            $first = $write('第一条');
            $second = $write('第二条');
            $third = $write('第三条');

            $ok = LogVerifier::verify($first, $third);
            same(0, $ok['break_total'], '刚写入的三条应校验通过：' . json_encode($ok['breaks'], JSON_UNESCAPED_UNICODE));
            same(3, $ok['checked'], '区间内应读到 3 条');

            // 链上字段确实落库了
            $r2 = $row($second);
            ok($r2['row_hash'] !== '', 'row_hash 应写入');
            same((string)$row($first)['row_hash'], (string)$r2['prev_hash'], '第二行的 prev_hash 应等于第一行的 row_hash');

            // 链尾状态应指向最后写入的那一行
            same($third, (int)(LogChain::state()['tail_id'] ?? 0), '链状态表的 tail_id 应指向最后一行');

            // tamper：改内容，不改哈希
            Db::name('log')->where('id', $second)->update(['message' => '被改过了']);
            $bad = LogVerifier::verify($first, $third);
            same(1, $bad['break_total'], '内容被改应报出 1 处断点');
            same($second, $bad['breaks'][0]['id'], '必须定位到被改的那一行');
            same('content_modified', $bad['breaks'][0]['reason'], '原因应为 content_modified');

            // 改回来即恢复（这条同时证明：判定依据是内容哈希，而不是"是否被 UPDATE 过"）
            Db::name('log')->where('id', $second)->update(['message' => '第二条']);
            same(0, LogVerifier::verify($first, $third)['break_total'], '恢复内容后应重新通过');
        } finally {
            $cleanup();
        }
    });

    test('删掉链中间的行 → link_broken（定位到后一行）', function () use ($write, $cleanup): void {
        try {
            $first = $write('A');
            $second = $write('B');
            $third = $write('C');

            Db::name('log')->where('id', $second)->delete();

            $bad = LogVerifier::verify($first, $third);
            same(1, $bad['break_total'], '删中间行应报出 1 处断点');
            same($third, $bad['breaks'][0]['id'], '断点应定位到接不上的那一行');
            same('link_broken', $bad['breaks'][0]['reason'], '原因应为 link_broken');
        } finally {
            $cleanup();
        }
    });

    test('链尾被改 → tail_stale（只有比对链状态才能发现）', function () use ($write, $cleanup): void {
        try {
            $write('tail');
            $state = LogChain::state();
            ok($state !== null, '链状态行应存在');

            $original = (int)$state['tail_id'];
            try {
                // 模拟「尾行被删」：只动链状态行，避免破坏真实数据
                Db::name('log_chain')->where('id', LogChain::CHAIN_ID)->update(['tail_id' => $original + 999]);

                $bad = LogVerifier::verify();
                same(1, $bad['break_total'], '链尾不符应报出 1 处断点');
                same('tail_stale', $bad['breaks'][0]['reason'], '原因应为 tail_stale');
            } finally {
                Db::name('log_chain')->where('id', LogChain::CHAIN_ID)->update(['tail_id' => $original]);
            }

            same(0, LogVerifier::verify()['break_total'], '恢复链尾后应重新通过');
        } finally {
            $cleanup();
        }
    });

    test('未纳入链的历史行只统计、不报错（不误报）', function (): void {
        // 全库校验：历史行（row_hash 为空）应计入 unchained，而不是断点
        $result = LogVerifier::verify();
        ok($result['unchained'] >= 0, 'unchained 应可统计');
        ok(
            $result['break_total'] === 0,
            '未入链的历史行不该被判为篡改：' . json_encode($result['breaks'], JSON_UNESCAPED_UNICODE)
        );
    }, true);

    test('清理用例数据并复位链锚点', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
