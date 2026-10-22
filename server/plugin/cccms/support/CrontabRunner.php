<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use support\Redis;
use think\facade\Db;
use Throwable;

/**
 * 定时任务执行器（调度进程、失败重试、「立即执行」共用）。
 *
 * 执行前会做一层**重叠保护**（`overlap`）与**超时判定**（`timeout`）：
 *   - 任务表里的 `running` / `running_at` 是运行锁。上一轮还没跑完时，
 *     `overlap=skip`（默认）直接跳过本次，`overlap=allow` 允许并发；
 *   - `timeout > 0` 且锁已超过该秒数，视为上一轮**卡死**：强制释放锁并写一条
 *     `status=3`（超时释放）的日志，然后继续本轮执行。
 *
 * ⚠️ 已知限制：任务在调度进程**同一个进程内**执行，PHP 无法从外部强杀正在执行的调用，
 * 因此「超时」只能做到「解锁 + 留痕 + 让后续调度恢复」，不能真正中断卡住的代码。
 * 需要硬超时的任务请自行在代码里做分段/协作式检查，或改用独立进程执行。
 *
 * 执行结果状态码（`sys_crontab_log.status`）：
 *   1 = 成功 · 0 = 失败 · 2 = 跳过（上次未结束） · 3 = 超时释放
 */
final class CrontabRunner
{
    /** 跳过日志的写入节流（秒）：长任务每个调度周期都跳过，不节流会刷爆日志表 */
    private const SKIP_LOG_INTERVAL = 60;

    /** @var array<int,int> 任务 id => 上次写「跳过」日志的时间 */
    private static array $lastSkipLog = [];

    /**
     * 纯执行（不做锁与超时判定），供需要自行控制调度时机的场景使用。
     *
     * **执行身份只由任务归属决定**（P1-2）：定时任务跑在平台进程里，而「立即执行」
     * 跑在操作者的请求里。若沿用 `request()->user`，同一个任务手动与定时会看到不同的
     * 数据范围、把数据写到不同租户。这里统一用「任务属主租户」执行：
     *   - 平台任务（`tenant_id = 0`）→ 平台身份；
     *   - 租户任务 → 以该租户身份，写入自动落到该租户。
     * 操作者身份只记在 `sys_crontab_log.source = manual` 上，不参与数据权限。
     *
     * @param array<string,mixed> $task
     * @return array{status:int,output:string,cost:int}
     */
    public static function run(array $task): array
    {
        return self::asOwner($task, static fn (): array => self::runTask($task));
    }

    /**
     * 以「任务属主租户」的身份执行一段代码（P1-2）。
     *
     * 同时把 `DataScope` 的用户上下文置空：行级范围不该取决于「谁点的立即执行」——
     * cron 进程本来就没有用户，手动执行也必须一致，否则同一任务两条路径结果不同。
     * 两者都用 try/finally 恢复，避免常驻进程把身份泄漏给后续请求。
     *
     * @param array<string,mixed> $task
     */
    private static function asOwner(array $task, callable $fn): mixed
    {
        $tenantId = (int)($task['tenant_id'] ?? TenantContext::PLATFORM_ID);

        return DataScope::runAs(
            null,
            static fn () => TenantContext::runAs($tenantId, static fn () => $fn())
        );
    }

    /**
     * 真正的任务调用（身份已由 `run()` / `execute()` 固定）。
     *
     * @param array<string,mixed> $task
     * @return array{status:int,output:string,cost:int}
     */
    private static function runTask(array $task): array
    {
        $target = trim((string)$task['target']);
        $start  = microtime(true);
        $status = 1;

        if (!class_exists($target) || !is_subclass_of($target, CrontabTask::class)) {
            $status = 0;
            $output = '目标非法：必须是在代码中实现 CrontabTask 的类（' . $target . '）';
        } else {
            try {
                $params = $task['params'] ?? [];
                if (is_string($params)) {
                    $params = json_decode($params, true) ?: [];
                }
                /** @var CrontabTask $instance */
                $instance = new $target();
                $output = $instance->run(is_array($params) ? $params : []);
            } catch (Throwable $e) {
                $status = 0;
                /*
                 * 只回显 getMessage() 常常定位不到位置：PHP 自身的错误（warning 被转成异常）
                 * 的 message 是一句固定文本，例如 "Array to string conversion"，
                 * 光看它不知道是哪一行把数组当字符串用了。因此补上 file:line，
                 * 完整堆栈另写框架日志（避免把绝对路径与调用链塞进任务日志表）。
                 */
                $output = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
                Log::error(
                    'crontab: task ' . $target . ' failed: ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
                );
            }
        }

        return [
            'status' => $status,
            'output' => (string)$output,
            'cost'   => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * 带重叠保护与超时判定地执行一次（**不写日志**，由调用方决定是否落库）。
     *
     * @param array<string,mixed> $task
     * @param string              $source cron / retry / manual
     * @return array{status:int,output:string,cost:int}
     */
    /**
     * 调度进程心跳键。
     *
     * 与「最近一次任务执行时间」是两个不同信号：任务全是每小时一次时，后者天然很旧，
     * 无法区分「调度进程死了」与「只是还没到点」。心跳年龄才是进程存活证据。
     */
    public const HEARTBEAT_KEY = 'cccms:crontab:heartbeat';

    /** 写一次心跳（由调度进程每秒调用）。失败不影响调度本身。 */
    public static function heartbeat(): void
    {
        try {
            Redis::setex(self::HEARTBEAT_KEY, 90, (string)time());
        } catch (Throwable) {
            // 心跳写失败不能中断调度
        }
    }

    public static function execute(array $task, int $now, string $source = 'cron'): array
    {
        // 认领 / 释放运行锁同样要落在任务属主租户下：平台账号手动执行租户任务时，
        // 若沿用操作者租户，锁会打在错误的租户行上（`sys_crontab` 参与租户隔离）。
        return self::asOwner($task, static fn (): array => self::executeWithLock($task, $now, $source));
    }

    /** 认领 → 执行 → 释放锁（执行身份已由 `execute()` 固定） */
    private static function executeWithLock(array $task, int $now, string $source): array
    {
        $id      = (int)($task['id'] ?? 0);
        $overlap = strtolower(trim((string)($task['overlap'] ?? 'skip')));
        $timeout = (int)($task['timeout'] ?? 0);

        if ($overlap !== 'allow' && $id > 0) {
            $claimed = self::claim($task, $now, $timeout);
            if ($claimed !== '') {
                // 上一轮仍在运行：跳过；日志按分钟节流
                self::logSkip($task, $now, $claimed);

                return ['status' => 2, 'output' => $claimed, 'cost' => 0];
            }
        }

        try {
            return self::run($task);
        } finally {
            if ($overlap !== 'allow' && $id > 0) {
                self::release($id);
            }
        }
    }

    /**
     * 记录执行日志并更新任务的上次 / 下次执行时间。
     *
     * @param array<string,mixed>                      $task
     * @param array{status:int,output:string,cost:int} $result
     */
    public static function writeLog(array $task, array $result, int $now, string $source = 'cron'): void
    {
        // 日志与「上次 / 下次执行时间」同样归属任务所属租户：平台账号手动执行租户任务时，
        // 若按操作者租户写，日志会落到平台，与 cron 侧不一致。
        self::asOwner($task, static fn () => self::writeLogInner($task, $result, $now, $source));
    }

    /** 真正的落库（身份已由 `writeLog()` 固定） */
    private static function writeLogInner(array $task, array $result, int $now, string $source): void
    {
        try {
            self::insertLog($task, $result, $now, $source);

            // 只有「正常 cron 调度」才推进 last/next run 时间轴；
            // 重试 / 手动执行是独立动作，不扰动任务的调度时间（严格独立的重试队列）。
            if ($source === 'cron' && !empty($task['id'])) {
                $next = CronMatcher::nextRunTime((string)$task['expression'], $now);
                Db::name('crontab')->where('id', (int)$task['id'])->update([
                    'last_run_time' => date('Y-m-d H:i:s', $now),
                    'next_run_time' => $next ? date('Y-m-d H:i:s', $next) : null,
                ]);
            }
        } catch (Throwable $e) {
            // 日志写入失败不影响任务本身
            Log::error('crontab: write log failed: ' . $e->getMessage());
        }
    }

    /**
     * 失败后向「独立重试队列」投递一次重试（与 cron 调度解耦）。
     *
     * 语义：`retry_times = N` 表示「失败后再试 N 次」，所以总执行次数最多 N + 1。
     * `attempt` 是本次重试的序号（cron 首次失败 = 1，重试再失败 = 上一次 + 1），
     * 超过 `retry_times` 就不再投递。
     *
     * @param array<string,mixed> $task
     */
    public static function enqueueRetry(array $task, int $now, int $attempt): void
    {
        $id = (int)($task['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $times = max(0, (int)($task['retry_times'] ?? 0));
        if ($times === 0 || $attempt < 1 || $attempt > $times) {
            return;
        }

        try {
            $interval = max(1, (int)($task['retry_interval'] ?? 60));
            Db::name('crontab_retry')->insert([
                'crontab_id' => $id,
                'attempt'    => $attempt,
                'retry_at'   => date('Y-m-d H:i:s', $now + $interval),
            ]);
        } catch (Throwable $e) {
            Log::error('crontab: enqueue retry failed: ' . $e->getMessage());
        }
    }

    /**
     * 尝试获取运行锁。
     *
     * @return string 空串 = 获取成功；非空 = 跳过原因
     */
    private static function claim(array $task, int $now, int $timeout): string
    {
        $id  = (int)$task['id'];
        $row = Db::name('crontab')->where('id', $id)->field('running,running_at')->find();

        if ($row && (int)$row['running'] === 1) {
            $runningAt = strtotime((string)($row['running_at'] ?? '')) ?: 0;
            $stuck     = $timeout > 0 && $runningAt > 0 && ($now - $runningAt) >= $timeout;

            if (!$stuck) {
                return '上次执行尚未结束，本次跳过（overlap=skip）';
            }

            // 超时：解锁并留痕（进程内无法真正中断，只能让后续调度恢复）
            Db::name('crontab')->where('id', $id)->update(['running' => 0, 'running_at' => null]);
            self::insertLog(
                $task,
                ['status' => 3, 'output' => "上次执行超过 {$timeout} 秒仍未结束，已强制释放运行锁", 'cost' => 0],
                $now,
                'timeout'
            );
        }

        Db::name('crontab')->where('id', $id)->update([
            'running'    => 1,
            'running_at' => date('Y-m-d H:i:s', $now),
        ]);

        return '';
    }

    private static function release(int $id): void
    {
        try {
            Db::name('crontab')->where('id', $id)->update(['running' => 0, 'running_at' => null]);
        } catch (Throwable $e) {
            Log::error('crontab: release lock failed: ' . $e->getMessage());
        }
    }

    /** 跳过日志按任务节流，避免「每秒任务 + 长执行」把日志表刷爆 */
    private static function logSkip(array $task, int $now, string $reason): void
    {
        $id   = (int)($task['id'] ?? 0);
        $last = self::$lastSkipLog[$id] ?? 0;
        if ($now - $last < self::SKIP_LOG_INTERVAL) {
            return;
        }
        self::$lastSkipLog[$id] = $now;

        try {
            self::insertLog($task, ['status' => 2, 'output' => $reason, 'cost' => 0], $now, 'cron');
        } catch (Throwable $e) {
            Log::error('crontab: write skip log failed: ' . $e->getMessage());
        }
    }

    /** @param array{status:int,output:string,cost:int} $result */
    private static function insertLog(array $task, array $result, int $now, string $source): void
    {
        Db::name('crontab_log')->insert([
            'crontab_id' => (int)($task['id'] ?? 0),
            'name'       => (string)($task['name'] ?? ''),
            'status'     => (int)$result['status'],
            'source'     => $source,
            'output'     => mb_substr((string)$result['output'], 0, 2000),
            'cost'       => (int)$result['cost'],
            'run_time'   => date('Y-m-d H:i:s', $now),
        ]);
    }
}
