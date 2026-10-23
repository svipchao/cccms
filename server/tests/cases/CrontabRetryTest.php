<?php

declare(strict_types=1);

use plugin\cccms\app\job\CrontabRetryJob;
use plugin\cccms\support\CrontabRunner;
use plugin\cccms\support\CrontabTask;
use plugin\cccms\support\JobHandler;
use plugin\cccms\support\JobQueue;
use think\facade\Db;

/** 故意失败的调度任务：重试逻辑只关心 status=0 */
final class CrontabItestFailTask implements CrontabTask
{
    public function run(array $params = []): string
    {
        throw new RuntimeException('itest 故意失败');
    }
}

/** 无副作用作业目标（复用作业队列的投递能力做 discard 用例） */
final class CrontabItestProbe implements JobHandler
{
    public function handle(array $payload): string
    {
        return 'probe';
    }
}

/**
 * F5 收尾：定时任务的失败重试从「表 + 轮询」改为**延迟作业**。
 *
 * 重点回归三件事：
 * 1. 投递是**延迟**的（`delay = retry_interval`）且**同轮幂等**（每秒任务连失败不会插一堆）；
 * 2. 一轮失败后由作业自己投递下一轮，`retry_times` 用尽即停（账只记在任务配置里）；
 * 3. 任务停用 / 删除后，排队中的重试直接作废（返回跳过，不进重试）。
 *
 * 需要真实 MySQL（`sys_crontab` / `sys_crontab_log` / `sys_job` / `sys_job_log`）。
 */
return static function (): void {
    suite('定时任务失败重试（作业化）');

    $marker = '__itest_retry__';

    $cleanup = static function () use ($marker): void {
        $ids = Db::name('crontab')->where('name', $marker)->column('id');
        if ($ids !== []) {
            Db::name('crontab_log')->whereIn('crontab_id', $ids)->delete();
            foreach ($ids as $id) {
                Db::name(JobQueue::TABLE)->where('unique_key', 'like', 'crontab-retry:' . (int)$id . ':%')->delete();
            }
            Db::name('crontab')->whereIn('id', $ids)->delete();
        }
        // 本用例只投递 `crontab-retry:*` 的作业，按前缀兜底清一遍（含执行日志）
        $jobIds = Db::name(JobQueue::TABLE)->where('unique_key', 'like', 'crontab-retry:%')->column('id');
        if ($jobIds !== []) {
            Db::name(JobQueue::LOG_TABLE)->whereIn('job_id', $jobIds)->delete();
            Db::name(JobQueue::TABLE)->whereIn('id', $jobIds)->delete();
        }
    };

    /** 建一条会失败的任务：retry_times=2 / retry_interval=1 */
    $makeTask = static function (int $status = 1) use ($marker): array {
        $id = (int)Db::name('crontab')->insertGetId([
            'tenant_id'      => 0,
            'name'           => $marker,
            'group_name'     => '',
            'expression'     => '0 0 2 * * *',
            'target'         => CrontabItestFailTask::class,
            'params'         => '{}',
            'status'         => $status,
            'overlap'        => 'skip',
            'timeout'        => 0,
            'retry_times'    => 2,
            'retry_interval' => 1,
            'remark'         => '',
        ]);

        return Db::name('crontab')->where('id', $id)->find();
    };

    $jobOf = static fn (int $taskId, int $attempt): ?array => Db::name(JobQueue::TABLE)
        ->where('unique_key', 'crontab-retry:' . $taskId . ':' . $attempt)
        ->find();

    test('投递重试：延迟到 retry_interval 之后，同轮只留一条', function () use ($makeTask, $jobOf, $cleanup): void {
        try {
            $task = $makeTask();
            $id   = (int)$task['id'];
            $at   = time();

            CrontabRunner::enqueueRetry($task, time(), 1);
            CrontabRunner::enqueueRetry($task, time(), 1); // 同一轮重复投递（每秒任务连失败）

            $job = $jobOf($id, 1);
            ok($job !== null, '应投递第 1 次重试作业');
            same('crontab', (string)$job['queue'], '重试作业应落在 crontab 队列');
            same(JobQueue::STATUS_PENDING, (int)$job['status'], '新重试作业应为待执行');
            same(1, (int)Db::name(JobQueue::TABLE)
                ->where('unique_key', 'crontab-retry:' . $id . ':1')->count(), '同轮重试应幂等，只留一条');

            $payload = json_decode((string)$job['payload'], true);
            same(1, (int)($payload['attempt'] ?? 0), 'payload 应带第几次重试');
            // 用投递前的时间点比较：`> time()` 会在秒边界上偶发失败（retry_interval 只有 1 秒）
            ok(
                strtotime((string)$job['available_at']) >= $at + 1,
                '应延迟到 retry_interval 之后再执行'
            );

            // retry_times=2：第 3 次不再投递
            CrontabRunner::enqueueRetry($task, time(), 3);
            ok($jobOf($id, 3) === null, '超过 retry_times 不应再投递');

            // 停用后不再投递
            Db::name('crontab')->where('id', $id)->update(['retry_times' => 0]);
            $task['retry_times'] = 0;
            CrontabRunner::enqueueRetry($task, time(), 1);
            same(1, (int)Db::name(JobQueue::TABLE)
                ->where('unique_key', 'crontab-retry:' . $id . ':1')->count(), 'retry_times=0 不应投递');
        } finally {
            $cleanup();
        }
    }, true);

    test('重试作业：执行失败写 retry 日志，并按次数投递下一轮', function () use ($makeTask, $jobOf, $cleanup): void {
        try {
            $task = $makeTask();
            $id   = (int)$task['id'];

            $out = (new CrontabRetryJob())->handle(['crontab_id' => $id, 'attempt' => 1]);
            contains('第 1 次重试', $out, '输出应说明是第几次重试');
            contains('失败', $out, '故意失败的任务应报失败');

            $logs = Db::name('crontab_log')->where('crontab_id', $id)->select()->toArray();
            same(1, count($logs), '应写一条执行日志');
            same('retry', (string)$logs[0]['source'], '来源应记 retry');
            same(0, (int)$logs[0]['status'], '日志状态应为失败');

            ok($jobOf($id, 2) !== null, '仍失败且还有次数，应投递第 2 次重试');

            // 第二轮：次数用尽，不再投递第 3 次
            (new CrontabRetryJob())->handle(['crontab_id' => $id, 'attempt' => 2]);
            ok($jobOf($id, 3) === null, 'retry_times 用尽后不应再投递');
            same(2, (int)Db::name('crontab_log')->where('crontab_id', $id)->count(), '两轮应各写一条日志');

            $row = Db::name('crontab')->where('id', $id)->find();
            same(0, (int)$row['running'], '执行结束应释放运行锁');
        } finally {
            $cleanup();
        }
    }, true);

    test('重试作业：任务停用 / 删除后直接作废，不再执行', function () use ($makeTask, $cleanup): void {
        try {
            $task   = $makeTask(0); // 停用
            $id     = (int)$task['id'];
            $before = (int)Db::name('crontab_log')->where('crontab_id', $id)->count();

            $out = (new CrontabRetryJob())->handle(['crontab_id' => $id, 'attempt' => 1]);
            contains('跳过', $out, '停用任务的重试应提示跳过');
            same($before, (int)Db::name('crontab_log')->where('crontab_id', $id)->count(), '停用任务不应执行、不应写日志');

            // 物理删除后再触发同样作废
            Db::name('crontab')->where('id', $id)->delete();
            contains('跳过', (new CrontabRetryJob())->handle(['crontab_id' => $id, 'attempt' => 1]), '已删任务的重试应提示跳过');
        } finally {
            $cleanup();
        }
    }, true);

    test('重试作业：缺少 crontab_id 抛异常（进重试/死信）', function (): void {
        try {
            (new CrontabRetryJob())->handle([]);
            fail('缺少 crontab_id 应抛异常，但没有抛');
        } catch (RuntimeException $e) {
            contains('crontab_id', $e->getMessage(), '异常应说明缺的是 crontab_id');
        }
    });

    test('撤销：discard 只删未结束的作业，已完成的保留', function () use ($cleanup): void {
        try {
            $prefix = 'crontab-retry:999001:';
            $pending = JobQueue::push(CrontabItestProbe::class, [], ['queue' => 'crontab-itest', 'unique' => $prefix . '1']);
            $done    = JobQueue::push(CrontabItestProbe::class, [], ['queue' => 'crontab-itest', 'unique' => $prefix . '2']);
            JobQueue::complete(['id' => $done, 'attempts' => 1], 'ok', 1);

            same(1, JobQueue::discard($prefix), '只应撤销待执行的那条');
            ok(Db::name(JobQueue::TABLE)->where('id', $pending)->find() === null, '待执行的应被删除');
            ok(Db::name(JobQueue::TABLE)->where('id', $done)->find() !== null, '已完成的应保留');
        } finally {
            $ids = Db::name(JobQueue::TABLE)->where('queue', 'crontab-itest')->column('id');
            if ($ids !== []) {
                Db::name(JobQueue::LOG_TABLE)->whereIn('job_id', $ids)->delete();
                Db::name(JobQueue::TABLE)->whereIn('id', $ids)->delete();
            }
            $cleanup();
        }
    }, true);

    test('清理用例数据', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
