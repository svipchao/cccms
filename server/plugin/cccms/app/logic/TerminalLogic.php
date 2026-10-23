<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\job\TerminalJob;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\I18n;
use plugin\cccms\support\JobQueue;
use plugin\cccms\support\Terminal;
use think\facade\Db;
use Throwable;

/**
 * 在线命令终端（F2）。
 *
 * ## 为什么执行要进队列
 *
 * 命令耗时不可控（日志归档可能几分钟）。在 HTTP 请求里同步跑会占住 worker、
 * 撞上网关超时后用户以为失败又点一次 —— 于是同一命令并行跑了两份。
 * 投递成作业后：立刻返回 job_id，前端轮询作业日志取输出；同一命令用幂等键互斥，
 * 连点两次只会得到同一条作业。
 *
 * ## 为什么不做数据权限
 *
 * `sys_job` 是平台级运维表，一条命令不属于某个部门；能看哪条完全由权限节点决定。
 * 历史记录只展示 `queue = terminal` 的作业，不与业务作业混在一起。
 */
final class TerminalLogic
{
    /** 终端作业独占的队列名：任务中心里可以靠它筛选，也方便将来单独起消费进程 */
    public const QUEUE = 'terminal';

    /** 历史记录条数：够看清「刚才跑的是什么」，不做分页（要翻旧账去任务中心） */
    private const HISTORY_LIMIT = 20;

    /**
     * 命令目录 + 终端开关。
     *
     * @return array<string,mixed>
     */
    public static function catalog(): array
    {
        return [
            'enabled'      => Terminal::enabled(),
            'timeout'      => Terminal::timeout(),
            'output_limit' => Terminal::outputLimit(),
            'commands'     => Terminal::commands(),
        ];
    }

    /**
     * 投递一条命令。
     *
     * @param  array<string,mixed> $params    客户端提交的原始参数（未校验）
     * @param  string              $confirm   危险命令的二次确认串：必须等于命令 key
     * @param  bool                $canDanger 是否拥有 `cccms:terminal:danger`
     * @param  int                 $userId    执行人（进 payload，排错与审计要能对上人）
     * @return array{job_id:int}
     */
    public static function run(string $key, array $params, string $confirm, bool $canDanger, int $userId): array
    {
        if (!Terminal::enabled()) {
            throw new ApiException(I18n::t('terminal.disabled'), 403);
        }

        $command = Terminal::find($key);
        if ($command === null) {
            throw new ApiException(I18n::t('terminal.not_found'), 404);
        }

        // 危险命令：权限 + 手输 key 两道，缺一不可。只靠前端弹窗等于没校验
        if ((int)$command['risk'] >= Terminal::RISK_DANGER) {
            if (!$canDanger) {
                throw new ApiException(I18n::t('terminal.danger_forbidden'), 403);
            }
            if ($confirm !== $command['key']) {
                throw new ApiException(I18n::t('terminal.confirm_required', ['key' => $command['key']]), 422);
            }
        }

        $validated = Terminal::validate($command, $params);

        try {
            $jobId = JobQueue::push(
                TerminalJob::class,
                [
                    'key'     => $command['key'],
                    'params'  => $validated,
                    'user_id' => $userId,
                ],
                [
                    'queue' => self::QUEUE,
                    // 命令带副作用：失败不自动重试（重跑一次可能比不跑更糟），人工确认后在任务中心重试
                    'max_attempts' => 1,
                    // 同一命令同时只允许一条在跑：连点两次得到同一条作业，而不是并行两份
                    'unique' => 'terminal:' . $command['key'],
                ]
            );
        } catch (Throwable $e) {
            throw new ApiException(I18n::t('terminal.push_failed', ['reason' => $e->getMessage()]), 500);
        }

        return ['job_id' => $jobId];
    }

    /**
     * 最近执行记录（只含终端队列）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function history(): array
    {
        try {
            $rows = Db::name(JobQueue::TABLE)
                ->where('queue', self::QUEUE)
                ->order('id', 'desc')
                ->limit(self::HISTORY_LIMIT)
                ->select()
                ->toArray();
        } catch (Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['id']           = (int)$row['id'];
            $row['status']       = (int)$row['status'];
            $row['attempts']     = (int)$row['attempts'];
            $row['max_attempts'] = (int)$row['max_attempts'];
            $row['payload']      = self::decodePayload($row['payload'] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * 单条作业 + 每次尝试的输出。
     *
     * 必须校验 `queue = terminal`：否则拿到任意 job_id 就能读到导出等其它作业的输出。
     *
     * @return array{job:array<string,mixed>,list:array<int,array<string,mixed>>}
     */
    public static function logs(int $jobId): array
    {
        $job = Db::name(JobQueue::TABLE)
            ->where('id', $jobId)
            ->where('queue', self::QUEUE)
            ->find();

        if (!$job) {
            throw new ApiException(I18n::t('terminal.job_not_found'), 404);
        }

        $job['id']           = (int)$job['id'];
        $job['status']       = (int)$job['status'];
        $job['attempts']     = (int)$job['attempts'];
        $job['max_attempts'] = (int)$job['max_attempts'];
        $job['payload']      = self::decodePayload($job['payload'] ?? null);

        return ['job' => $job, 'list' => JobQueue::logs($jobId)];
    }

    /** @return array<string,mixed> */
    private static function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        $decoded = json_decode((string)$payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
