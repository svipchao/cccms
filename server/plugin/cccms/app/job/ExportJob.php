<?php

declare(strict_types=1);

namespace plugin\cccms\app\job;

use plugin\cccms\support\ExportTask;
use plugin\cccms\support\JobHandler;

/**
 * 生成一份异步导出归档（F5 的第一个真实消费者）。
 *
 * 之前是「`sys_export_task` + 每 10 秒轮询」：导出请求落一行 pending 就返回，
 * 由 `ExportTaskConsumer` 这个**定时任务**扫表消费。问题在于：
 *   - 最坏要等 10 秒才开始生成，用户以为卡住了；
 *   - 没有失败重试，一次异常就永久 pending；
 *   - 与 cron 抢同一个进程，长导出会把调度 tick 拖后。
 *
 * 现在改成投递一条作业：入队即可被消费进程立刻取走，失败按退避重试，
 * 用尽进死信可人工重试。`sys_export_task` 仍是业务事实源（列表 / 下载接口不变），
 * 作业只负责「什么时候去生成」。
 */
final class ExportJob implements JobHandler
{
    public function handle(array $payload): string
    {
        $taskId = (int)($payload['task_id'] ?? 0);
        if ($taskId <= 0) {
            // 抛异常而不是 return：这是**参数错误**，应该进重试/死信让人看见
            throw new \InvalidArgumentException('缺少 task_id');
        }

        // 生成失败时 run() 会把任务放回「待处理」并抛异常，交给框架按退避重试（用尽进死信）；
        // 返回 false 只表示「没抢到这条任务」（已被 cron / 下载补生成处理），属正常情况，不能当失败重试。
        $done = ExportTask::run($taskId);

        return $done
            ? "导出任务 #{$taskId} 已生成"
            : "导出任务 #{$taskId} 已被其他消费者处理或状态不符，跳过";
    }
}
