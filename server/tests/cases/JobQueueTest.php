<?php

declare(strict_types=1);

use plugin\cccms\app\job\ExportJob;
use plugin\cccms\app\logic\JobLogic;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\ExportTask;
use plugin\cccms\support\JobHandler;
use plugin\cccms\support\JobQueue;
use think\facade\Db;

/**
 * 测试用作业目标：只回显参数、不产生副作用。
 *
 * 万一真实 worker 在这期间消费到测试作业，也只是返回一段字符串并标记成功，
 * 不会碰任何业务数据。
 */
final class JobQueueItestProbe implements JobHandler
{
    public function handle(array $payload): string
    {
        return 'probe:' . (string)($payload['mark'] ?? '');
    }
}

/**
 * F5：异步作业队列（投递 / 幂等 / 认领 / 成功 / 退避重试 / 死信 / 崩溃回收）
 * 与第一个真实消费者 `ExportJob`。
 *
 * 重点回归两条**容易被悄悄改坏**的语义：
 * 1. `attempts` 在**认领时**就 +1（进程被 kill 也不会无限重跑）；
 * 2. 导出生成失败时任务回退「待处理」并抛异常交给作业重试，而不是当场定死「失败」。
 *
 * 需要真实 MySQL（`sys_job` / `sys_job_log` / `sys_export_task`）。
 */
return static function (): void {
    suite('异步作业队列（F5）');

    $queue  = 'jobq-itest';
    $userId = 99999;

    $cleanupJobs = static function () use ($queue): void {
        $ids = Db::name(JobQueue::TABLE)->where('queue', $queue)->column('id');
        if ($ids !== []) {
            Db::name(JobQueue::LOG_TABLE)->whereIn('job_id', $ids)->delete();
        }
        Db::name(JobQueue::TABLE)->where('queue', $queue)->delete();
    };

    $cleanupExports = static function () use ($userId): void {
        foreach (Db::name('export_task')->where('user_id', $userId)->select()->toArray() as $row) {
            if (($row['file_path'] ?? '') !== '') {
                @unlink(base_path() . '/runtime/' . $row['file_path']);
            }
        }
        Db::name('export_task')->where('user_id', $userId)->delete();
    };

    /** 投递一条指向测试 target 的作业 */
    $push = static function (array $payload, array $options = []) use ($queue): int {
        return JobQueue::push(JobQueueItestProbe::class, $payload, $options + [
            'queue'        => $queue,
            'max_attempts' => 3,
        ]);
    };

    /**
     * 认领到指定作业。
     *
     * 库里若还有别的可执行作业（上一次用例残留 / 真实环境），reserve() 可能先拿到它们：
     * 原样退回待执行（attempts 减回认领时 +1 的那次），避免误伤真实数据。
     */
    $claim = static function (int $id): array {
        for ($i = 0; $i < 50; $i++) {
            $job = JobQueue::reserve();
            if ($job === null) {
                break;
            }
            if ((int)$job['id'] === $id) {
                return $job;
            }
            Db::name(JobQueue::TABLE)->where('id', (int)$job['id'])->update([
                'status'      => JobQueue::STATUS_PENDING,
                'reserved_at' => null,
                'attempts'    => Db::raw('attempts - 1'),
            ]);
        }

        fail('没有认领到刚投递的作业 #' . $id . '（数据库里积压了别的作业？）');
    };

    test('投递：目标必须是 JobHandler，否则直接拒绝（不投一条注定失败的作业）', function (): void {
        try {
            JobQueue::push('stdClass', [], ['queue' => 'jobq-itest-invalid']);
            fail('非法目标应抛异常，但没有抛');
        } catch (ApiException $e) {
            contains('JobHandler', $e->getMessage(), '应说明目标必须实现 JobHandler');
            same(422, $e->getCode(), '非法目标应是 422');
        }
    });

    test('幂等键：同一 unique 只入队一条，重复投递返回既有 id', function () use ($push, $queue, $cleanupJobs): void {
        try {
            $first  = $push(['mark' => 'a'], ['unique' => $queue . ':dup']);
            $second = $push(['mark' => 'b'], ['unique' => $queue . ':dup']);

            same($first, $second, '同幂等键应返回同一作业 id');
            same(1, (int)Db::name(JobQueue::TABLE)->where('queue', $queue)->count(), '同幂等键只应落一行');

            $row = Db::name(JobQueue::TABLE)->where('id', $first)->find();
            same(JobQueue::STATUS_PENDING, (int)$row['status'], '新作业应为待执行');
            same(3, (int)$row['max_attempts'], 'max_attempts 应落库');
            $payload = json_decode((string)$row['payload'], true);
            same('a', $payload['mark'] ?? null, 'payload 应是首次投递的参数');
        } finally {
            $cleanupJobs();
        }
    }, true);

    test('认领 → 成功：0 → 1 → 2，完成后释放幂等槽位', function () use ($push, $queue, $claim, $cleanupJobs): void {
        try {
            $id  = $push(['mark' => 'ok'], ['unique' => $queue . ':ok']);
            $job = $claim($id);

            same(JobQueue::STATUS_RUNNING, (int)$job['status'], '认领后应为执行中');
            same(1, (int)$job['attempts'], '认领时就该 +1 次尝试（进程被 kill 也算一次）');

            JobQueue::complete($job, 'done', 12);

            $row = Db::name(JobQueue::TABLE)->where('id', $id)->find();
            same(JobQueue::STATUS_DONE, (int)$row['status'], '完成后应为成功');
            ok($row['unique_key'] === null, '成功后应释放幂等槽位');
            ok($row['finished_at'] !== null, '成功应写结束时间');

            $logs = JobQueue::logs($id);
            same(1, count($logs), '应写一条执行日志');
            same(1, (int)$logs[0]['status'], '执行日志应为成功');
            contains('done', (string)$logs[0]['output'], '执行日志应记录输出');
        } finally {
            $cleanupJobs();
        }
    }, true);

    test('失败：还有次数进「失败待重试」并退避，用尽进死信并释放幂等槽位', function () use ($push, $queue, $claim, $cleanupJobs): void {
        try {
            $id  = $push([], ['unique' => $queue . ':retry', 'max_attempts' => 2]);
            $job = $claim($id);

            $before = time();
            JobQueue::fail($job, '第一次失败', 5);

            $row = Db::name(JobQueue::TABLE)->where('id', $id)->find();
            same(JobQueue::STATUS_RETRY, (int)$row['status'], '还有余额应进失败待重试');
            ok($row['unique_key'] !== null, '未结束前应保留幂等槽位');
            // 与「失败前的时刻」比较，避免秒边界导致的偶发失败
            ok(strtotime((string)$row['available_at']) >= $before + 5, '应退避到未来再执行');
            contains('第一次失败', (string)$row['last_error'], '失败原因应落库');

            // 模拟第二次尝试也用尽：认领后 attempts 已到上限
            Db::name(JobQueue::TABLE)->where('id', $id)->update([
                'status'      => JobQueue::STATUS_RUNNING,
                'attempts'    => 2,
                'reserved_at' => date('Y-m-d H:i:s'),
            ]);
            JobQueue::fail(['id' => $id, 'attempts' => 2, 'max_attempts' => 2], '第二次失败', 7);

            $dead = Db::name(JobQueue::TABLE)->where('id', $id)->find();
            same(JobQueue::STATUS_DEAD, (int)$dead['status'], '次数用尽应进死信');
            ok($dead['unique_key'] === null, '死信应释放幂等槽位（修好后可重新投递）');
            ok($dead['finished_at'] !== null, '死信应写结束时间');
        } finally {
            $cleanupJobs();
        }
    }, true);

    test('人工重试：重置 attempts 回到待执行；已完成的不允许重试', function () use ($push, $claim, $cleanupJobs): void {
        try {
            $id = $push([]);

            $job = $claim($id);
            JobQueue::fail($job, 'boom', 3);
            JobQueue::retry($id);

            $row = Db::name(JobQueue::TABLE)->where('id', $id)->find();
            same(JobQueue::STATUS_PENDING, (int)$row['status'], '重试后应回到待执行');
            same(0, (int)$row['attempts'], '重试应重置尝试次数');
            same('', (string)$row['last_error'], '重试应清空失败原因');

            $again = $claim($id);
            JobQueue::complete($again, 'ok', 1);

            try {
                JobQueue::retry($id);
                fail('已完成的作业重试应抛异常，但没有抛');
            } catch (ApiException $e) {
                same(422, $e->getCode(), '已完成应拒绝重试（422）');
            }
        } finally {
            $cleanupJobs();
        }
    }, true);

    test('崩溃回收：执行中过期后有名额回待执行，没名额进死信', function () use ($queue, $cleanupJobs): void {
        $stale = date('Y-m-d H:i:s', time() - JobQueue::STALE_SECONDS - 60);

        $insert = static function (int $attempts, int $max) use ($queue, $stale): int {
            return (int)Db::name(JobQueue::TABLE)->insertGetId([
                'queue'        => $queue,
                'target'       => JobQueueItestProbe::class,
                'payload'      => '{}',
                'status'       => JobQueue::STATUS_RUNNING,
                'attempts'     => $attempts,
                'max_attempts' => $max,
                'available_at' => $stale,
                'reserved_at'  => $stale,
            ]);
        };

        try {
            $alive = $insert(1, 3);
            $dead  = $insert(3, 3);

            ok(JobQueue::recoverStale() >= 2, '应回收至少 2 个过期作业');

            $aliveRow = Db::name(JobQueue::TABLE)->where('id', $alive)->find();
            same(JobQueue::STATUS_PENDING, (int)$aliveRow['status'], '还有余额应回待执行续跑');
            ok($aliveRow['reserved_at'] === null, '回收后应清空认领时间');

            $deadRow = Db::name(JobQueue::TABLE)->where('id', $dead)->find();
            same(JobQueue::STATUS_DEAD, (int)$deadRow['status'], '次数用尽应进死信，不能无限重跑');
        } finally {
            $cleanupJobs();
        }
    }, true);

    test('ExportJob：按 task_id 生成归档，重复执行只生成一次', function () use ($userId, $cleanupExports): void {
        try {
            $id  = ExportTask::create('log', [], $userId);
            $out = (new ExportJob())->handle(['task_id' => $id]);
            contains('已生成', $out, '首次执行应报告生成完成');

            $task = Db::name('export_task')->where('id', $id)->find();
            same(ExportTask::STATUS_DONE, (int)$task['status'], '作业执行后任务应为完成');
            ok(is_file(base_path() . '/runtime/' . $task['file_path']), '归档文件应已生成');

            ok(ExportTask::run($id) === false, '已结束的任务再执行应返回 false（不算失败）');
            contains('跳过', (new ExportJob())->handle(['task_id' => $id]), '重复执行应提示跳过而不是重复生成');
        } finally {
            $cleanupExports();
        }
    }, true);

    test('ExportJob：缺少 task_id 抛异常（进重试/死信，而不是静默成功）', function (): void {
        try {
            (new ExportJob())->handle([]);
            fail('缺少 task_id 应抛异常，但没有抛');
        } catch (InvalidArgumentException $e) {
            contains('task_id', $e->getMessage(), '异常应说明缺的是 task_id');
        }
    });

    test('导出失败：任务回退待处理并抛异常，交给作业退避重试', function () use ($userId, $cleanupExports): void {
        try {
            $id = ExportTask::create('__itest_unknown__', [], $userId);

            try {
                ExportTask::run($id);
                fail('未知导出类型应抛异常，但没有抛');
            } catch (ApiException $e) {
                contains('导出类型', $e->getMessage(), '应报未知导出类型');
            }

            $task = Db::name('export_task')->where('id', $id)->find();
            same(
                ExportTask::STATUS_PENDING,
                (int)$task['status'],
                '作业路径失败应回退待处理（让重试 / cron / 下载补生成接手），而不是定死失败'
            );
            ok((string)$task['error'] !== '', '失败原因应落库');
        } finally {
            $cleanupExports();
        }
    }, true);

    test('任务中心：列表筛选 / 统计 / 执行日志 / 删除限制', function () use ($push, $queue, $cleanupJobs): void {
        try {
            $pendingId = $push(['mark' => 'p'], ['unique' => $queue . ':list-p']);
            $doneId    = $push(['mark' => 'd'], ['unique' => $queue . ':list-d']);
            // 这条用例只验证「读模型」，不重复走认领：直接按 id 落成成功（状态机本身另有用例覆盖）
            JobQueue::complete(['id' => $doneId, 'attempts' => 1], 'ok', 3);

            $page = JobLogic::paginate(['queue' => $queue, 'page' => 1, 'limit' => 20]);
            same(2, $page['total'], '按队列筛选应命中 2 条');

            $done = null;
            foreach ($page['list'] as $item) {
                if ($item['id'] === $doneId) {
                    $done = $item;
                }
            }
            ok($done !== null, '列表应包含已完成的作业');
            same('JobQueueItestProbe', $done['target_label'], '目标应下发短类名');
            same('d', $done['payload']['mark'] ?? null, 'payload 应解码成数组');

            same(1, JobLogic::paginate(['queue' => $queue, 'status' => '0'])['total'], '按状态筛选只应命中待执行那条');

            $stats = JobLogic::stats();
            ok($stats['total'] >= 2, '统计总数应包含本用例作业');
            ok(in_array($queue, $stats['queues'], true), '统计应带出队列名供筛选下拉使用');

            $logs = JobLogic::logs($doneId);
            same($doneId, (int)$logs['job']['id'], '日志接口应带出作业本身');
            same(1, count($logs['list']), '完成过一次应有 1 条执行日志');

            try {
                JobLogic::logs(999999999);
                fail('不存在的作业应抛异常');
            } catch (ApiException $e) {
                same(404, $e->getCode(), '不存在的作业应是 404');
            }

            try {
                JobLogic::remove([$pendingId]);
                fail('待执行作业不应被删除，但没有抛异常');
            } catch (ApiException $e) {
                same(422, $e->getCode(), '未结束的作业应拒绝删除（422）');
            }

            same(1, JobLogic::remove([$doneId]), '已完成的作业可以删除');
        } finally {
            $cleanupJobs();
        }
    }, true);

    test('清理用例数据', function () use ($cleanupJobs, $cleanupExports): void {
        $cleanupJobs();
        $cleanupExports();
        ok(true);
    }, true);
};
