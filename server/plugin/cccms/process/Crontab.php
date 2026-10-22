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
 * 定时任务调度进程。
 *
 * 每秒 tick 一次，做两件**相互独立**的事（顺序固定，互不干扰）：
 *   1. **消费独立重试队列**：`sys_crontab_retry` 里 `retry_at <= now` 的任务立即执行一次，
 *      与 cron 表达式无关；失败再按 `retry_interval` 投递下一次（`attempt + 1`）；
 *   2. **正常 cron 调度**：按六段表达式判断到期，`markAndCheckHit` 按秒去重。
 *
 * 「严格独立」的含义：重试是**额外**的一次执行，它只按自己的 `retry_at` 触发，
 * 不会「吃掉」同秒到期的正常调度 —— 旧实现把 `retry_left/retry_at` 写在 `sys_crontab` 上、
 * 又用 `handled` 集合让重试跳过 cron，导致重试与 cron 同秒命中时正常调度被吞掉。
 * 现在重试队列是独立的一张表，每条重试都是独立的一行，互不覆盖。
 *
 * 重叠保护 / 超时判定在 `CrontabRunner::execute()` 里（基于任务表的运行锁）。
 * 不做「注册式」调度，因此增删改任务无需 reload 进程。
 * 注意：任务在本进程内**串行**执行，某个任务跑很久会顺延后续 tick；
 * 自定义进程仅在 Linux/macOS 下启动（Windows 不支持）。
 */
class Crontab
{
    /** 每 tick 最多消费的重试条数，避免积压时一次 tick 跑太久 */
    private const RETRY_BATCH = 100;

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

        // ---- ① 独立重试队列（与 cron 调度严格解耦） ----
        $this->processRetries($now);

        // ---- ② 正常 cron 调度（不受重试影响） ----
        $this->processCron($now);
    }

    private function processRetries(int $now): void
    {
        try {
            $jobs = Db::name('crontab_retry')
                ->where('retry_at', '<=', date('Y-m-d H:i:s', $now))
                ->order('id', 'asc')
                ->limit(self::RETRY_BATCH)
                ->select()
                ->toArray();
        } catch (Throwable $e) {
            Log::error('crontab: load retry queue failed: ' . $e->getMessage());

            return;
        }

        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            $task  = $this->findTask((int)$job['crontab_id']);

            // 任务已删 / 停用：丢弃残留的重试任务
            if ($task === null) {
                Db::name('crontab_retry')->where('id', $jobId)->delete();
                continue;
            }

            $result = CrontabRunner::execute($task, $now, 'retry');
            CrontabRunner::writeLog($task, $result, $now, 'retry');

            // 消费本次重试
            Db::name('crontab_retry')->where('id', $jobId)->delete();

            // 仍失败且还有剩余次数：投递下一次重试
            if ((int)$result['status'] === 0) {
                CrontabRunner::enqueueRetry($task, $now, (int)$job['attempt'] + 1);
            }
        }
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

    /**
     * 取一个启用且未删除的任务（重试消费用）。
     *
     * @return array<string,mixed>|null
     */
    private function findTask(int $id): ?array
    {
        $task = SoftDelete::apply(Db::name('crontab'))->where('id', $id)->where('status', 1)->find();

        return $task ?: null;
    }
}
