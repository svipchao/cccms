<?php

declare(strict_types=1);

namespace plugin\cccms\app\job;

use plugin\cccms\support\CrontabRunner;
use plugin\cccms\support\JobHandler;
use RuntimeException;

/**
 * 定时任务的「失败重试」消费（F5 的最后一块）。
 *
 * 之前是 `sys_crontab_retry` + 调度进程每秒扫表：重试与 cron 共用同一个 tick，
 * 长任务会把重试一起拖后；重试记录没有失败可见性，卡住了也没人知道。
 * 现在改成**延迟作业**：`available_at = 现在 + retry_interval`，由作业进程到期取走，
 * 失败还能进死信在「定时任务 → 作业队列」里看到并手工重试。
 *
 * ## 谁负责「下一次」
 *
 * 作业只跑**一轮**（payload 里的 `attempt` 固定）：本轮仍然失败时，由这里主动投递
 * `attempt + 1`，直到用完 `retry_times`。这样「重试次数」的账只在任务配置里，
 * 不会和作业框架的 `max_attempts` 各记一套。
 */
final class CrontabRetryJob implements JobHandler
{
    public function handle(array $payload): string
    {
        $id      = (int)($payload['crontab_id'] ?? 0);
        $attempt = max(1, (int)($payload['attempt'] ?? 1));

        if ($id <= 0) {
            // 参数错误：抛异常进重试 / 死信，让人看见
            throw new RuntimeException('缺少 crontab_id');
        }

        // 任务已删除 / 停用 / 进回收站：本轮重试作废。
        // 返回而不是抛异常 —— 这不是「执行失败」，重试它也不会变好，抛了只会白占三次退避。
        $task = CrontabRunner::findEnabled($id);
        if ($task === null) {
            return "任务 #{$id} 已删除或停用，跳过第 {$attempt} 次重试";
        }

        $now    = time();
        $result = CrontabRunner::execute($task, $now, 'retry');
        CrontabRunner::writeLog($task, $result, $now, 'retry');

        // 仍失败且还有剩余次数：投递下一轮（`enqueueRetry` 自己会按 retry_times 截断）
        if ((int)$result['status'] === 0) {
            CrontabRunner::enqueueRetry($task, $now, $attempt + 1);
        }

        $label = match ((int)$result['status']) {
            1       => '成功',
            2       => '跳过（上次未结束）',
            default => '失败',
        };

        return "第 {$attempt} 次重试：{$label}（{$result['cost']}ms）：{$result['output']}";
    }
}
