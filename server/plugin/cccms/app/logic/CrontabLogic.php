<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Crontab;
use plugin\cccms\app\model\CrontabLog;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\CronMatcher;
use plugin\cccms\support\CrontabRunner;
use plugin\cccms\support\CrontabTask;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\SortInput;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\facade\Db;

/**
 * 定时任务逻辑。
 *
 * 查询统一走 `Crontab` 模型：该表既无归属列也无部门列，模型里声明了 `no_baseline`
 * （见 `app/model/Crontab.php`），隔离完全交给自定义行级规则；
 * 同时它也是**调度进程**的数据源，CLI 下没有当前用户，作用域自动跳过。
 */
final class CrontabLogic
{
    /**
     * 列头可排序列：前端 prop => 真实列名。
     *
     * `next_run_time` 在查询后被 `CronMatcher` 重算了一遍（按当前时间），
     * 按库里的值排序会和展示值对不上，故意不登记；`desc` 是前端派生的表达式说明，同样不登记。
     */
    private const SORTABLE = [
        // 只留「上次执行」：任务名/分组/表达式/目标/状态排序没有实际价值
        'last_run_time' => 'last_run_time',
    ];

    /**
     * 可写字段白名单。
     *
     * 运行时列（`running` / `running_at`）**不允许**通过接口改写 ——
     * 它们由调度进程维护，手工改会造成「锁没释放」。
     */
    private const FIELDS = [
        'name', 'group_name', 'expression', 'target', 'params',
        'status', 'overlap', 'timeout', 'retry_times', 'retry_interval', 'remark',
    ];

    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除）
        $query = !empty($params['trashed']) ? Crontab::onlyTrashed() : Crontab::newScopedQuery();
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        if (!empty($params['group_name'])) {
            $query->where('group_name', 'like', '%' . $params['group_name'] . '%');
        }
        // 列头筛选支持多选，值形如 `1,0`
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        // 最近执行 / 下次执行时间范围（列头时间筛选，值已归一化为 Y-m-d H:i:s）
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);
        if ($start !== '') {
            $query->where('last_run_time', '>=', $start);
        }
        if ($end !== '') {
            $query->where('last_run_time', '<=', $end);
        }
        [$nextStart, $nextEnd] = FilterInput::range($params['next_start'] ?? null, $params['next_end'] ?? null);
        if ($nextStart !== '') {
            $query->where('next_run_time', '>=', $nextStart);
        }
        if ($nextEnd !== '') {
            $query->where('next_run_time', '<=', $nextEnd);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE);
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$row) {
            $next = CronMatcher::nextRunTime((string)$row['expression'], time());
            $row['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    /** 可作为调度目标的任务类（实现 CrontabTask）。扫描所有插件的 plugin/*\/command/task。 */
    public static function targets(): array
    {
        $result = [];
        foreach (glob(base_path() . '/plugin/*/command/task', GLOB_ONLYDIR) ?: [] as $dir) {
            // .../plugin/{插件}/command/task → 命名空间 plugin\{插件}\command\task\
            $plugin    = basename(dirname($dir, 2));
            $namespace = 'plugin\\' . $plugin . '\\command\\task\\';
            $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel   = substr($file->getPathname(), strlen($dir) + 1, -4);
                $class = $namespace . str_replace(DIRECTORY_SEPARATOR, '\\', (string)$rel);
                if (class_exists($class) && is_subclass_of($class, CrontabTask::class)) {
                    // label 带插件前缀，避免多插件同名任务类无法区分（如两个插件都有 DailyReportTask）
                    $result[] = [
                        'class' => $class,
                        'label' => $plugin . '/' . substr((string)strrchr($class, '\\'), 1),
                    ];
                }
            }
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return $result;
    }

    public static function create(array $data): int
    {
        self::assertValid($data);
        $data = self::prepare($data);
        $data['params'] = self::encodeParams($data['params'] ?? []);
        $next = CronMatcher::nextRunTime((string)$data['expression'], time());
        $data['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;

        // 新增还没有归属，插入语句不需要数据权限条件
        return (int)Crontab::withoutGlobalScope()->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        // 先拿到当前行：未提交的字段用原值合并后校验，避免「只改 status」被当成「表达式非法」
        $current = self::assertInScope($id);
        $data    = self::prepare($data);
        self::assertValid(array_merge($current, $data));

        if (array_key_exists('params', $data)) {
            $data['params'] = self::encodeParams($data['params']);
        }

        // 只有 expression 本次有变化才重算 next_run_time，否则保留调度器维护的值
        if (array_key_exists('expression', $data)) {
            $next = CronMatcher::nextRunTime((string)$data['expression'], time());
            $data['next_run_time'] = $next ? date('Y-m-d H:i:s', $next) : null;
        }

        Crontab::newScopedQuery()->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        self::assertInScope($id);
        // 软删除：进回收站。执行日志是历史记录，刻意保留（调度进程查询已自动排除已删任务）
        Crontab::destroy($id);
        // 丢弃该任务在独立重试队列里的残留，避免已删任务被重试消费
        Db::name('crontab_retry')->where('crontab_id', $id)->delete();
    }

    /**
     * 立即执行一次（不改变调度周期，也不改动失败重试状态）。
     *
     * 走 `execute()` 而不是 `run()`：同样受重叠保护约束 ——
     * 如果该任务正在执行中，手工触发也会被跳过（返回 status=2），避免并发跑同一任务。
     */
    public static function runOnce(int $id): array
    {
        $task   = self::assertInScope($id);
        $now    = time();
        $result = CrontabRunner::execute($task, $now, 'manual');
        CrontabRunner::writeLog($task, $result, $now, 'manual');

        return $result;
    }

    public static function logs(array $params): array
    {
        // 执行日志随任务归属（模型声明为不参与数据权限），不单独做范围判定
        $query = CrontabLog::newScopedQuery();
        if (!empty($params['crontab_id'])) {
            $query->where('crontab_id', (int)$params['crontab_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /** @param array<string,mixed> $data */
    private static function assertValid(array $data): void
    {
        $expression = (string)($data['expression'] ?? '');
        if (CronMatcher::nextRunTime($expression, time()) === null) {
            throw new ApiException(I18n::t('crontab.expression_invalid'), 422);
        }

        $target = (string)($data['target'] ?? '');
        if (!class_exists($target) || !is_subclass_of($target, CrontabTask::class)) {
            throw new ApiException(I18n::t('crontab.target_invalid'), 422);
        }

        // ---- 重叠保护 / 超时 / 重试 / 分组 ----
        $overlap = strtolower(trim((string)($data['overlap'] ?? 'skip')));
        if (!in_array($overlap, ['skip', 'allow'], true)) {
            throw new ApiException(I18n::t('crontab.overlap_invalid'), 422);
        }

        if ((int)($data['timeout'] ?? 0) < 0) {
            throw new ApiException(I18n::t('crontab.timeout_invalid'), 422);
        }

        $retryTimes = (int)($data['retry_times'] ?? 0);
        if ($retryTimes < 0 || $retryTimes > 10) {
            throw new ApiException(I18n::t('crontab.retry_times_invalid'), 422);
        }

        if ((int)($data['retry_interval'] ?? 60) < 1) {
            throw new ApiException(I18n::t('crontab.retry_interval_invalid'), 422);
        }

        if (mb_strlen((string)($data['group_name'] ?? '')) > 32) {
            throw new ApiException(I18n::t('crontab.group_name_too_long'), 422);
        }
    }

    /**
     * 字段白名单过滤：运行时列（running / running_at）不允许通过接口改写。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function prepare(array $data): array
    {
        return FilterInput::only($data, self::FIELDS);
    }

    /** @return string */
    private static function encodeParams(mixed $params): string
    {
        if (is_string($params)) {
            return $params === '' ? '{}' : $params;
        }
        return (string)json_encode($params ?: new \stdClass(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 数据范围校验：在范围内才返回任务实体（越权 403、不存在 404）。
     *
     * 范围由 `Crontab` 模型的全局作用域注入；`withoutGlobalScope()` 那次查库只用于
     * 区分「不存在」与「越权」，不参与业务。
     *
     * @return array<string,mixed>
     */
    private static function assertInScope(int $id): array
    {
        $task = Crontab::newScopedQuery()->where('id', $id)->find();
        if ($task) {
            return $task->toArray();
        }

        if (!Crontab::withoutGlobalScope()->where('id', $id)->find()) {
            throw new ApiException(I18n::t('crontab.not_found'), 404);
        }

        throw new ApiException(I18n::t('crontab.no_permission'), 403);
    }
}
