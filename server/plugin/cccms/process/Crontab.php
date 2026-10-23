<?php

declare(strict_types=1);

namespace plugin\cccms\process;

use plugin\cccms\support\CronMatcher;
use plugin\cccms\support\CrontabRunner;
use plugin\cccms\support\SoftDelete;
use support\Log;
use think\facade\Db;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 定时任务调度进程：**只负责「什么时候跑」**。
 *
 * 每秒 tick 一次，按六段表达式判断到期，`markAndCheckHit` 按秒去重后执行。
 * 失败重试**不在本进程**：它是一次「替我再跑一遍」的事件，由 `CrontabRetryJob`
 * 延迟投递到作业队列（`process/Queue`），到期即被消费。分开的理由：
 *   - 长任务会顺延后续 tick，重试跟着一起被拖后（旧实现：`sys_crontab_retry` 扫表）；
 *   - 重试失败没有可见性：作业表有 attempts / last_error / 死信，能查、能手工重试。
 *
 * 重叠保护 / 超时判定在 `CrontabRunner::execute()` 里（基于任务表的运行锁）。
 * 不做「注册式」调度，因此增删改任务无需 reload 进程。
 * 注意：任务在本进程内**串行**执行，某个任务跑很久会顺延后续 tick；
 * 自定义进程仅在 Linux/macOS 下启动（Windows 不支持）。
 */
class Crontab
{
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(1, function () {
            $this->tick();
        });
    }

    private function tick(): void
    {
        $now = time();

        // 心跳：证明调度进程本身还活着。/metrics 的 cccms_crontab_heartbeat_age_seconds 读它。
        CrontabRunner::heartbeat();

        $this->processCron($now);
    }

    private function processCron(int $now): void
    {
        try {
            // 已进回收站的任务不再调度
            $tasks = SoftDelete::apply(Db::name('crontab'))->where('status', 1)->select()->toArray();
        } catch (Throwable $e) {
            Log::error('crontab: load tasks failed: ' . $e->getMessage());

            return;
        }

        foreach ($tasks as $task) {
            if (!CronMatcher::isDue((string)$task['expression'], $now)) {
                continue;
            }
            // 同一秒只执行一次
            if (CronMatcher::markAndCheckHit((string)$task['id'], $now)) {
                continue;
            }

            $result = CrontabRunner::execute($task, $now, 'cron');
            CrontabRunner::writeLog($task, $result, $now, 'cron');

            if ((int)$result['status'] === 0) {
                CrontabRunner::enqueueRetry($task, $now, 1);
            }
        }
    }
}
