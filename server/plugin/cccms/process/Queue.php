<?php

declare(strict_types=1);

namespace plugin\cccms\process;

use plugin\cccms\support\JobHandler;
use plugin\cccms\support\JobQueue;
use RuntimeException;
use support\Log;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 作业队列消费进程（F5）。
 *
 * 每秒 tick 一次，每次最多认领 `BATCH` 条，跑完就退出本拍 —— 不做「一次把队列抽干」，
 * 否则积压时会把下一拍无限推迟，`available_at` 到期的延迟作业也跟着延后。
 *
 * ## 与 `Crontab` 进程的分工
 *
 * `Crontab` 只负责「按时间触发」（cron 表达式 + 独立重试表），本进程只负责
 * 「把一条已投递的作业执行一次」。**不要把一次性任务塞进 crontab** ——
 * 那会退化成「每秒扫表 + 状态位」的伪队列，且没有退避 / 死信 / 幂等。
 *
 * ## 停机语义
 *
 * `onWorkerStop` / `onWorkerReload` 会把本进程认领中但没跑完的作业按「失败」落账：
 * 有剩余次数就排下一次，用尽则进死信。这样不必等 `STALE_SECONDS` 才回收，
 * 也不会出现「重启一下任务就永远卡在执行中」。
 *
 * 注意：自定义进程仅在 Linux/macOS 启动（Windows 不支持），与 `Crontab` 一致。
 */
class Queue
{
    /** 每 tick 最多消费条数：兼顾吞吐与「别把下一拍拖死」 */
    private const BATCH = 10;

    /** 回收「认领后进程死掉」的作业的间隔（秒） */
    private const RECOVER_INTERVAL = 60;

    /**
     * 本进程已认领、尚未落结果的作业。
     *
     * @var array<int,array<string,mixed>>
     */
    private array $inflight = [];

    private int $lastRecover = 0;

    public function onWorkerStart(Worker $worker): void
    {
        JobQueue::heartbeat();
        // 上一次进程被 kill 留下的「执行中」记录：启动时先清一遍账
        $recovered = JobQueue::recoverStale();
        if ($recovered > 0) {
            Log::warning("job: recovered {$recovered} stale job(s) on start");
        }
        $this->lastRecover = time();

        Timer::add(1, function (): void {
            $this->tick();
        });
    }

    public function onWorkerStop(Worker $worker): void
    {
        $this->releaseInflight('进程停机，作业已退回队列');
    }

    public function onWorkerReload(Worker $worker): void
    {
        $this->releaseInflight('进程重载，作业已退回队列');
    }

    private function tick(): void
    {
        JobQueue::heartbeat();

        if (time() - $this->lastRecover >= self::RECOVER_INTERVAL) {
            JobQueue::recoverStale();
            $this->lastRecover = time();
        }

        for ($i = 0; $i < self::BATCH; $i++) {
            $job = JobQueue::reserve();
            if ($job === null) {
                return;
            }
            $this->run($job);
        }
    }

    /** @param array<string,mixed> $job */
    private function run(array $job): void
    {
        $id = (int)$job['id'];
        $this->inflight[$id] = $job;
        $start = microtime(true);

        try {
            $target = (string)$job['target'];
            // 双保险：投递时已校验过，这里再校验一次，防止库里被手工改成任意类
            if (!class_exists($target) || !is_subclass_of($target, JobHandler::class)) {
                throw new RuntimeException('目标非法：必须是在代码中实现 JobHandler 的类（' . $target . '）');
            }

            $payload = $job['payload'] ?? [];
            if (is_string($payload)) {
                $payload = json_decode($payload, true) ?: [];
            }

            /** @var JobHandler $handler */
            $handler = new $target();
            $output = $handler->handle(is_array($payload) ? $payload : []);

            JobQueue::complete($job, (string)$output, self::costMs($start));
        } catch (Throwable $e) {
            // 完整堆栈写框架日志（含绝对路径），作业表只留可读原因
            Log::error(
                'job: #' . $id . ' ' . (string)$job['target'] . ' failed: ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
            );
            JobQueue::fail(
                $job,
                $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                self::costMs($start)
            );
        } finally {
            unset($this->inflight[$id]);
        }
    }

    /** 停机时把认领中的作业按失败落账（保留 attempts 语义，用尽即进死信） */
    private function releaseInflight(string $reason): void
    {
        foreach ($this->inflight as $job) {
            try {
                JobQueue::fail($job, $reason, 0);
            } catch (Throwable $e) {
                Log::error('job: release on stop failed: ' . $e->getMessage());
            }
        }
        $this->inflight = [];
    }

    private static function costMs(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }
}
