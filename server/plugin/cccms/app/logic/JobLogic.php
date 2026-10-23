<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\JobQueue;
use plugin\cccms\support\PageInput;
use plugin\cccms\support\SortInput;
use think\facade\Db;
use Throwable;

/**
 * 作业队列只读视图 + 运维动作（F5 任务中心）。
 *
 * ## 为什么不做数据权限
 *
 * `sys_job` 是**平台级**运维表：一条作业不属于某个部门/用户，判定「你能看哪条」
 * 没有业务含义；而且队列是全局单条序列，按数据范围裁剪只会让排障时看不到真正卡住的那条。
 * 访问控制完全交给权限节点（`cccms:job:*`，挂在「定时任务」菜单下）。
 *
 * ## 状态由 `JobQueue` 唯一定义
 *
 * 这里不重复声明状态常量，也不自己写状态流转 —— 展示用的标签由前端按
 * `JobQueue::STATUS_*` 的语义渲染，后端只做查询与转发，避免两处状态机漂移。
 */
final class JobLogic
{
    /** 可排序列：运维只关心「什么时候该跑」和「试了几次」 */
    private const SORTABLE = [
        'id'           => 'id',
        'available_at' => 'available_at',
        'attempts'     => 'attempts',
        'create_time'  => 'create_time',
    ];

    /**
     * 作业列表。
     *
     * `payload` 原样解码下发：它通常只有一两个键（如 `{"task_id": 12}`），
     * 抽屉里要看到「这条作业到底在替谁干活」，隐藏它等于让人去翻库。
     *
     * @param array<string,mixed> $params
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public static function paginate(array $params): array
    {
        $query = Db::name(JobQueue::TABLE);

        $queue = trim((string)($params['queue'] ?? ''));
        if ($queue !== '') {
            $query->where('queue', $queue);
        }

        $target = trim((string)($params['target'] ?? ''));
        if ($target !== '') {
            $query->where('target', 'like', '%' . $target . '%');
        }

        // 列头筛选支持多选，值形如 `0,3`
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE);
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']           = (int)$row['id'];
            $row['status']       = (int)$row['status'];
            $row['attempts']     = (int)$row['attempts'];
            $row['max_attempts'] = (int)$row['max_attempts'];
            $row['target_label'] = self::shortClass((string)$row['target']);
            $row['payload']      = self::decodePayload($row['payload'] ?? null);
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    /**
     * 统计卡数据：各状态计数 + 消费者心跳年龄 + 现有队列名（给筛选下拉用）。
     *
     * @return array<string,mixed>
     */
    public static function stats(): array
    {
        $stats = JobQueue::stats();

        try {
            $stats['queues'] = array_values(array_filter(
                Db::name(JobQueue::TABLE)->distinct(true)->column('queue'),
                static fn ($q) => is_string($q) && $q !== ''
            ));
        } catch (Throwable) {
            // 统计本身可用就够了，队列名取不到不该让整块卡片报错
            $stats['queues'] = [];
        }

        return $stats;
    }

    /**
     * 单条作业的详情 + 每次尝试的执行日志（最新在前）。
     *
     * @return array{job:array<string,mixed>,list:array<int,array<string,mixed>>}
     */
    public static function logs(int $id): array
    {
        $job = Db::name(JobQueue::TABLE)->where('id', $id)->find();
        if (!$job) {
            throw new ApiException(I18n::t('job.not_found'), 404);
        }

        $job['payload']      = self::decodePayload($job['payload'] ?? null);
        $job['target_label'] = self::shortClass((string)$job['target']);

        return ['job' => $job, 'list' => JobQueue::logs($id)];
    }

    /** 人工重试：打回待执行并重置尝试次数（语义见 `JobQueue::retry()`） */
    public static function retry(int $id): void
    {
        JobQueue::retry($id);
    }

    /**
     * 删除作业（只允许已成功 / 死信）。
     *
     * 0 条被删时不静默成功：用户勾的多半是「待执行 / 执行中」，说清楚为什么没删，
     * 比回一句「删除成功」却在列表里还看得见要好。
     *
     * @param array<int,int> $ids
     */
    public static function remove(array $ids): int
    {
        $deleted = JobQueue::remove($ids);
        if ($deleted === 0) {
            throw new ApiException(I18n::t('job.remove_none'), 422);
        }

        return $deleted;
    }

    /** `plugin\cccms\app\job\ExportJob` → `ExportJob`（列表里显示短名，完整类名进抽屉） */
    private static function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * payload 解码：库里是 json 列，驱动可能回字符串也可能回数组。
     *
     * @return array<string,mixed>
     */
    private static function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        $decoded = json_decode((string)$payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
