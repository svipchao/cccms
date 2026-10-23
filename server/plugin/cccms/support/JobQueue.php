<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use support\Redis;
use think\facade\Db;
use Throwable;

/**
 * 异步作业队列（F5）。
 *
 * ## 为什么以数据库为事实源
 *
 * 队列里最不能丢的是「任务本身」。把作业存在 Redis 里，一旦 flush / 换实例 / 内存淘汰，
 * 任务就凭空消失且无从对账；而 `sys_job` 是普通表，能查、能改、能补跑、能进备份。
 * Redis 在这里只承担**心跳**这类可丢的信息。
 *
 * 同理，**幂等去重走唯一索引而不是 Redis SETNX**：`unique_key` 加唯一索引，
 * 且只对「未结束」的作业生效（结束时置 NULL，MySQL 唯一索引允许多个 NULL，
 * 等价于部分唯一索引）。Redis 挂了不会让幂等失效。
 *
 * ## 状态机
 *
 * ```
 *   0 待执行 ──认领──> 1 执行中 ──成功──> 2 成功（终态）
 *      ↑                  │
 *      │                  └──失败且还有次数──> 3 失败待重试 ──到期──> 0/1
 *      └──────────────────────── 失败且次数用尽 ──> 4 死信（终态，可人工重试）
 * ```
 *
 * `attempts` 在**认领时**就 +1，而不是等失败才加：进程被 kill 时根本没有机会写失败，
 * 靠认领计数才能避免「一个必崩的作业被无限重跑」。
 */
final class JobQueue
{
    public const TABLE = 'job';
    public const LOG_TABLE = 'job_log';

    public const STATUS_PENDING = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_DONE    = 2;
    public const STATUS_RETRY   = 3;
    public const STATUS_DEAD    = 4;

    /** 执行中作业的认领过期时间：超过即认为进程已死，回收重跑 */
    public const STALE_SECONDS = 900;

    /** 退避上限（秒）：前几次 5/10/20… 最多 5 分钟 */
    private const MAX_BACKOFF = 300;

    /** 输出文本落库上限，防止一个作业把日志表写爆 */
    private const OUTPUT_LIMIT = 2000;

    public const HEARTBEAT_KEY = 'cccms:job:heartbeat';

    /**
     * 投递一条作业。
     *
     * @param array<string,mixed> $payload
     * @param array{queue?:string,delay?:int,max_attempts?:int,unique?:string|null} $options
     * @return int 作业 ID（幂等命中时返回既有作业的 ID）
     */
    public static function push(string $target, array $payload = [], array $options = []): int
    {
        // 提前校验：不合法直接拒绝，而不是投一条注定失败、还要重试三次的作业
        if (!class_exists($target) || !is_subclass_of($target, JobHandler::class)) {
            throw new ApiException(
                I18n::t('job.target_invalid', ['target' => $target]),
                422
            );
        }

        $queue = (string)($options['queue'] ?? 'default');
        $delay = max(0, (int)($options['delay'] ?? 0));
        $maxAttempts = max(1, min(10, (int)($options['max_attempts'] ?? 3)));
        $unique = isset($options['unique']) && $options['unique'] !== ''
            ? mb_substr((string)$options['unique'], 0, 191)
            : null;

        if ($unique !== null) {
            $existing = Db::name(self::TABLE)->where('unique_key', $unique)->value('id');
            if ($existing) {
                return (int)$existing;
            }
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'queue'        => $queue,
            'target'       => $target,
            'payload'      => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'       => self::STATUS_PENDING,
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => date('Y-m-d H:i:s', time() + $delay),
            'unique_key'   => $unique,
            'create_time'  => $now,
            'update_time'  => $now,
        ];

        try {
            return (int)Db::name(self::TABLE)->insertGetId($data);
        } catch (Throwable $e) {
            // 并发下另一个请求刚刚插入了同一个幂等键：唯一索引挡住了我们，返回对方那条
            if ($unique !== null) {
                $existing = Db::name(self::TABLE)->where('unique_key', $unique)->value('id');
                if ($existing) {
                    return (int)$existing;
                }
            }

            throw $e;
        }
    }

    /**
     * 认领一条到期作业（返回 null 表示当前没有可执行的）。
     *
     * 用「行锁 + 再校验状态」而不是 `SKIP LOCKED`：本项目默认单 worker，
     * 但多 worker 时同一行会被顺序认领，第二个拿不到（status 已不是 0/3），结果仍然正确。
     *
     * @return array<string,mixed>|null
     */
    public static function reserve(): ?array
    {
        $now = date('Y-m-d H:i:s');

        try {
            Db::startTrans();
            $row = Db::name(self::TABLE)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETRY])
                ->where('available_at', '<=', $now)
                ->order('id', 'asc')
                ->lock(true)
                ->find();

            if (!$row) {
                Db::commit();

                return null;
            }

            $claimed = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETRY])
                ->update([
                    'status'      => self::STATUS_RUNNING,
                    'reserved_at' => $now,
                    'attempts'    => Db::raw('attempts + 1'),
                    'update_time' => $now,
                ]);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            Log::error('job: reserve failed: ' . $e->getMessage());

            return null;
        }

        if ($claimed !== 1) {
            return null;
        }

        $row['status'] = self::STATUS_RUNNING;
        $row['attempts'] = (int)$row['attempts'] + 1;

        return $row;
    }

    /** 标记成功（终态）。 */
    public static function complete(array $job, string $output, int $cost): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name(self::TABLE)->where('id', (int)$job['id'])->update([
            'status'      => self::STATUS_DONE,
            'reserved_at' => null,
            'finished_at' => $now,
            'unique_key'  => null,
            'last_error'  => '',
            'update_time' => $now,
        ]);

        self::log((int)$job['id'], (int)$job['attempts'], 1, $cost, $output);
        self::notify($job, 'done');
    }

    /**
     * 标记失败：还有次数就按退避排下一次，用尽则进死信。
     *
     * 死信不自动删除 —— 它需要人看一眼原因，删了等于把问题藏起来。
     */
    public static function fail(array $job, string $error, int $cost): void
    {
        $now = date('Y-m-d H:i:s');
        $attempts = (int)$job['attempts'];
        $max = max(1, (int)($job['max_attempts'] ?? 3));
        $exhausted = $attempts >= $max;

        $data = [
            'status'      => $exhausted ? self::STATUS_DEAD : self::STATUS_RETRY,
            'reserved_at' => null,
            'last_error'  => mb_substr($error, 0, 500),
            'update_time' => $now,
        ];
        if ($exhausted) {
            $data['finished_at'] = $now;
            // 释放幂等槽位：同键可以重新投递（例如修完 bug 后补跑）
            $data['unique_key'] = null;
        } else {
            $data['available_at'] = date('Y-m-d H:i:s', time() + self::backoff($attempts));
        }

        Db::name(self::TABLE)->where('id', (int)$job['id'])->update($data);
        self::log((int)$job['id'], $attempts, 0, $cost, $error);
        self::notify($job, $exhausted ? 'dead' : 'retry');
    }

    /**
     * 作业状态变化时给「投递人」推一条事件（F9）。
     *
     * 只有 payload 里带 `user_id` 的作业才推：系统定时任务没有人可通知。
     * 推送失败不影响作业落账（`Realtime::publish()` 内部静默）——
     * 任务中心本来就靠接口拉取，推送只是让「跑完了」这件事不用等下一次刷新。
     *
     * @param array<string,mixed> $job
     */
    private static function notify(array $job, string $status): void
    {
        $payload = $job['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        Realtime::publish($userId, 'job', [
            'job_id' => (int)$job['id'],
            'status' => $status,
            'target' => (string)($job['target'] ?? ''),
        ]);
    }

    /**
     * 回收「认领后进程就没了」的作业。
     *
     * 两个分支不能合并：已用尽次数的直接进死信，否则重置成待执行会让一个必崩的作业无限重跑。
     *
     * @return int 回收条数
     */
    public static function recoverStale(int $seconds = self::STALE_SECONDS): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(60, $seconds));
        $now = date('Y-m-d H:i:s');
        $recovered = 0;

        try {
            $recovered += Db::name(self::TABLE)
                ->where('status', self::STATUS_RUNNING)
                ->where('reserved_at', '<', $cutoff)
                ->whereRaw('attempts >= max_attempts')
                ->update([
                    'status'      => self::STATUS_DEAD,
                    'reserved_at' => null,
                    'finished_at' => $now,
                    'unique_key'  => null,
                    'last_error'  => '执行中进程退出，且已达最大重试次数',
                    'update_time' => $now,
                ]);

            $recovered += Db::name(self::TABLE)
                ->where('status', self::STATUS_RUNNING)
                ->where('reserved_at', '<', $cutoff)
                ->update([
                    'status'       => self::STATUS_PENDING,
                    'reserved_at'  => null,
                    'available_at' => $now,
                    'last_error'   => '执行中进程退出，已重新排队',
                    'update_time'  => $now,
                ]);
        } catch (Throwable $e) {
            Log::error('job: recover stale failed: ' . $e->getMessage());

            return $recovered;
        }

        return $recovered;
    }

    /** 指数退避：5 / 10 / 20 / 40 … 封顶 5 分钟（第 1 次失败后等 5 秒） */
    public static function backoff(int $attempts): int
    {
        $attempts = max(1, $attempts);

        return (int)min(self::MAX_BACKOFF, 5 * (2 ** min(10, $attempts - 1)));
    }

    /** 各状态计数 + 队列进程心跳年龄（秒；null = 从未心跳或 Redis 不可用） */
    public static function stats(): array
    {
        $out = [
            'pending' => 0,
            'running' => 0,
            'done'    => 0,
            'retry'   => 0,
            'dead'    => 0,
            'total'   => 0,
        ];

        try {
            $rows = Db::name(self::TABLE)
                ->field('status, COUNT(*) AS total')
                ->group('status')
                ->select()->toArray();
        } catch (Throwable) {
            $rows = [];
        }

        $map = [
            self::STATUS_PENDING => 'pending',
            self::STATUS_RUNNING => 'running',
            self::STATUS_DONE    => 'done',
            self::STATUS_RETRY   => 'retry',
            self::STATUS_DEAD    => 'dead',
        ];
        foreach ($rows as $row) {
            $key = $map[(int)$row['status']] ?? null;
            if ($key === null) {
                continue;
            }
            $out[$key] = (int)$row['total'];
            $out['total'] += (int)$row['total'];
        }

        $out['heartbeat_age'] = self::heartbeatAge();

        return $out;
    }

    /**
     * 人工重试：把作业打回待执行，并**重置 attempts**。
     *
     * 已完成的作业不允许重试（终态），否则一次误点会把「已完成」变成「待执行」，
     * 用户看到的状态会来回跳。
     */
    public static function retry(int $id): void
    {
        $job = Db::name(self::TABLE)->where('id', $id)->find();
        if (!$job) {
            throw new ApiException(I18n::t('job.not_found'), 404);
        }
        if ((int)$job['status'] === self::STATUS_DONE) {
            throw new ApiException(I18n::t('job.already_done'), 422);
        }

        $now = date('Y-m-d H:i:s');
        Db::name(self::TABLE)->where('id', $id)->update([
            'status'       => self::STATUS_PENDING,
            'attempts'     => 0,
            'available_at' => $now,
            'reserved_at'  => null,
            'finished_at'  => null,
            'last_error'   => '',
            'update_time'  => $now,
        ]);
    }

    /**
     * 删除作业（只允许已结束的：待执行 / 执行中的删掉等于丢任务）。
     *
     * @param array<int,int> $ids
     * @return int 实际删除条数
     */
    public static function remove(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        $deleted = Db::name(self::TABLE)
            ->whereIn('id', $ids)
            ->whereIn('status', [self::STATUS_DONE, self::STATUS_DEAD])
            ->delete();

        if ($deleted > 0) {
            Db::name(self::LOG_TABLE)->whereIn('job_id', $ids)->delete();
        }

        return (int)$deleted;
    }

    /**
     * 按幂等键前缀撤销**未结束**的作业（只删待执行 / 待重试，返回条数）。
     *
     * 用于「业务对象已经没了，排队中的作业也该一起作废」：例如定时任务被删除后，
     * 它的失败重试链不该再跑。已结束（成功 / 死信）的不动 —— 那是历史记录，
     * 删掉等于抹掉「曾经重试过几次」的证据。
     */
    public static function discard(string $uniquePrefix): int
    {
        $prefix = trim($uniquePrefix);
        if ($prefix === '') {
            return 0;
        }

        return (int)Db::name(self::TABLE)
            ->where('unique_key', 'like', $prefix . '%')
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETRY])
            ->delete();
    }

    /** 单条作业的执行日志（最新在前） */
    public static function logs(int $jobId): array
    {
        return Db::name(self::LOG_TABLE)
            ->where('job_id', $jobId)
            ->order('id', 'desc')
            ->limit(50)
            ->select()->toArray();
    }

    public static function heartbeat(): void
    {
        try {
            Redis::set(self::HEARTBEAT_KEY, (string)time());
        } catch (Throwable) {
            // 心跳失败不影响消费
        }
    }

    public static function heartbeatAge(): ?int
    {
        try {
            $value = Redis::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return null;
        }

        return $value === null || $value === false || $value === '' ? null : max(0, time() - (int)$value);
    }

    private static function log(int $jobId, int $attempt, int $status, int $cost, string $output): void
    {
        try {
            Db::name(self::LOG_TABLE)->insert([
                'job_id'      => $jobId,
                'attempt'     => max(1, $attempt),
                'status'      => $status,
                'cost'        => max(0, $cost),
                'output'      => mb_substr($output, 0, self::OUTPUT_LIMIT),
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // 日志写失败不能反过来把作业判成失败（结果已经写进 sys_job 了）
            Log::error('job: write log failed: ' . $e->getMessage());
        }
    }
}
