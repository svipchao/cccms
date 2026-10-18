<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * 审计日志链校验（`cccms:log-verify` 的内核）。
 *
 * ## 能查出什么
 *
 * | 现象 | 判定 | 说明 |
 * |------|------|------|
 * | 改过某行任意字段 | `content_modified` | 重算哈希与库中 `row_hash` 不一致 |
 * | 删掉链中间的行 | `link_broken` | 后一行的 `prev_hash` 接不上前一行 |
 * | 删掉链尾几行 | `tail_stale` | 链状态表记的链尾与实际最后一行不符（删尾是常见掩盖手段） |
 * | 干净 | ok | — |
 *
 * ## 刻意**不**报为问题的情形
 *
 * - **未纳入链的行**（`row_hash` 为空）：本能力上线前的历史行，以及链表不可用时的
 *   降级写入。它们无法被证明，也不该被当成篡改，只统计条数（`unchained`）。
 * - **校验起点那行的 `prev_hash`**：归档 / 清理 / 管理员删除都会合法地拿掉链的头部，
 *   起点的前驱可能已经不存在，因此只从起点往后校验（`anchor_id` 由 `LogChain::reanchor()` 维护）。
 * - **链中间的合法删除**：应用删除操作日志（`/log/delete`）只会 reanchor 头部，
 *   中间被删仍会报 `link_broken` —— 它与「有人偷偷删了一行」在数据上不可区分，
 *   因此**如实报出**，由人工结合操作记录判断。
 */
final class LogVerifier
{
    /** 单批读取条数：链可能很长，一次拉全表会爆内存 */
    private const CHUNK = 1000;

    /** 最多记录多少条断点（全表被改时不至于把输出刷爆） */
    private const MAX_BREAKS = 50;

    /**
     * 校验一段区间。
     *
     * @param int $from 起始 id（含）；0 = 从链状态表的校验起点开始
     * @param int $to   结束 id（含）；0 = 到链尾（并检查链尾是否被删）
     *
     * @return array{
     *   from:int,to:int,checked:int,unchained:int,ok:bool,
     *   breaks:array<int,array{id:int,reason:string,detail:string}>,
     *   break_total:int,first_id:int,last_id:int,chain:array<string,mixed>|null
     * }
     */
    public static function verify(int $from = 0, int $to = 0): array
    {
        $chain = LogChain::state();

        $start = $from > 0 ? $from : (int)($chain['anchor_id'] ?? 0);
        $end   = $to;

        $totals = [
            'from'        => $start,
            'to'          => $end,
            'checked'     => 0,
            'unchained'   => 0,
            'ok'          => true,
            'breaks'      => [],
            'break_total' => 0,
            'first_id'    => 0,
            'last_id'     => 0,
            'chain'       => $chain,
        ];

        $prevHash  = null;   // 上一条**带哈希**的行的 row_hash
        $lastHash  = '';
        $lastChainedId = 0;  // 最后一条**带哈希**的行（链尾比对必须用它，而不是最后被读到的行）
        $cursor    = $start > 0 ? $start - 1 : 0;

        while (true) {
            $query = Db::name('log')->order('id', 'asc')->limit(self::CHUNK);
            if ($cursor > 0) {
                $query->where('id', '>', $cursor);
            }
            if ($end > 0) {
                $query->where('id', '<=', $end);
            }

            $rows = $query->select()->toArray();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $totals['checked']++;
                $id   = (int)$row['id'];
                $hash = (string)($row['row_hash'] ?? '');

                if ($totals['first_id'] === 0) {
                    $totals['first_id'] = $id;
                }
                $totals['last_id'] = $id;
                $cursor = $id;

                if ($hash === '') {
                    // 未纳入链：跳过，且不打断「前驱」的追踪（链上它等于不存在）
                    $totals['unchained']++;
                    continue;
                }

                // 按行上的哈希版本重算：历史行是 16 字段（v0），新行含 action_id / session_id（v1）
                $expected = LogChain::hash(
                    (string)($row['prev_hash'] ?? ''),
                    LogChain::normalize($row, (int)($row['hash_version'] ?? 0))
                );
                if ($expected !== $hash) {
                    self::addBreak($totals, $id, 'content_modified', '重算哈希与库中 row_hash 不一致（行内容被改过）');
                } elseif ($prevHash !== null && (string)($row['prev_hash'] ?? '') !== $prevHash) {
                    self::addBreak(
                        $totals,
                        $id,
                        'link_broken',
                        'prev_hash 接不上前一条记录（中间有行被删除或替换）'
                    );
                }

                $prevHash = $hash;
                $lastHash = $hash;
                $lastChainedId = $id;
            }
        }

        // 只有「校验到链尾」时才检查链尾一致性：指定了 --to 就是在校验历史片段
        if ($end === 0) {
            self::checkTail($totals, $chain, $lastChainedId, $lastHash);
        }

        $totals['ok'] = $totals['break_total'] === 0;

        return $totals;
    }

    /**
     * 链尾一致性：链状态表记的 `tail_id` / `tail_hash` 必须与**最后一条带哈希的行**相符。
     *
     * 这是**唯一**能发现「尾行被删」的手段：中间行被删会断链，但删掉尾部只会让链
     * 看起来"正常地变短了"。
     *
     * ⚠️ 比对对象必须是「最后一条**带哈希**的行」，不能用「最后一条被读到的行」：
     * 本能力上线前的历史行没有哈希、且在 id 上排在后面（比如升级当天新写的日志之后
     * 还有更早导入的数据），拿它们去比会天天误报。
     *
     * @param array<string,mixed> $totals
     * @param array<string,mixed>|null $chain
     * @param int $lastChainedId 最后一条带哈希的行 id（0 = 本次区间内没有任何带哈希的行）
     */
    private static function checkTail(array &$totals, ?array $chain, int $lastChainedId, string $lastHash): void
    {
        // 区间内没有带哈希的行：链尚未开始（或只校验了历史片段），不判定
        if ($lastChainedId === 0) {
            return;
        }

        if ($chain === null) {
            $totals['break_total']++;
            $totals['breaks'][] = [
                'id'     => 0,
                'reason' => 'chain_missing',
                'detail' => '链上已有记录但状态行不存在：无法判断尾行是否被删（可能被人删掉了 sys_log_chain 的整行）',
            ];

            return;
        }

        $tailId   = (int)($chain['tail_id'] ?? 0);
        $tailHash = (string)($chain['tail_hash'] ?? '');

        if ($tailId !== $lastChainedId || ($tailHash !== '' && $tailHash !== $lastHash)) {
            $totals['break_total']++;
            $totals['breaks'][] = [
                'id'     => $lastChainedId,
                'reason' => 'tail_stale',
                'detail' => sprintf(
                    '链尾不符：状态表记 id=%d hash=%s，实际 id=%d hash=%s（尾行可能被删除或替换）',
                    $tailId,
                    $tailHash === '' ? '(空)' : substr($tailHash, 0, 12) . '…',
                    $lastChainedId,
                    $lastHash === '' ? '(空)' : substr($lastHash, 0, 12) . '…'
                ),
            ];
        }
    }

    /**
     * @param array<string,mixed> $totals
     */
    private static function addBreak(array &$totals, int $id, string $reason, string $detail): void
    {
        $totals['break_total']++;
        if (count($totals['breaks']) < self::MAX_BREAKS) {
            $totals['breaks'][] = ['id' => $id, 'reason' => $reason, 'detail' => $detail];
        }
    }

    /**
     * 链上是否可校验（表与列是否就绪）。
     *
     * 用于命令给出「还没升级」这类明确提示，而不是报一堆"全部未纳入链"。
     */
    public static function ready(): bool
    {
        try {
            Db::name('log')->where('row_hash', '<>', '')->count();
            Db::name('log_chain')->count();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
