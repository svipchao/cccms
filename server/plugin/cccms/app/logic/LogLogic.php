<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\job\ExportJob;
use plugin\cccms\app\model\OperationLog;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\ClientIp;
use plugin\cccms\support\Csv;
use plugin\cccms\support\ExportTask;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\JobQueue;
use plugin\cccms\support\LogChain;
use plugin\cccms\support\PageInput;
use plugin\cccms\support\SortInput;
use support\Log;
use Throwable;

/**
 * 日志逻辑：操作日志与登录日志统一存 `sys_log`，登录记录固定 `path=/auth/login`
 * （中间件不记该路径，二者天然互斥），页面按「请求路径」筛选即可。
 *
 * 查询统一走 `OperationLog` 模型：它**参与**数据权限（仅本人 = 自己的日志、
 * 本部门 = 可见部门成员的日志，见模型里的 `$dataScope`），所以越权数据在列表与
 * 删除/清空上都自然不可见、不可动，这里不需要额外判定。
 *
 * 日志表没有软删除列，删除即物理删除。
 */
final class LogLogic
{
    /**
     * 登录记录的固定路径。
     *
     * 操作日志中间件**不记该路径**，登录记录由 `recordLogin()` 自己写，二者天然互斥；
     * 因此「筛选登录记录」只需要按这一条 path 过滤，不需要再加类型列（`type` 列已下线）。
     */
    private const LOGIN_PATH = '/auth/login';

    /** 请求链路上限：一次请求正常只有几条记录，给足余量即可 */
    private const TRACE_LIMIT = 200;

    /** 操作链路上限：一次操作（进页面并发拉数据 / 保存 + 回读）通常几条到几十条 */
    private const ACTION_LIMIT = 500;

    /** 会话轨迹单次读取条数（游标分页，前端「加载更多」） */
    private const SESSION_PAGE = 200;

    /** 会话轨迹单次上限：再大只会让首屏变慢，需要更多就继续游标 */
    private const SESSION_PAGE_MAX = 500;

    /** 列头可排序列：前端 prop => 真实列名（未登记的列回落默认排序） */
    private const SORTABLE = [
        // 日志排障只关心「多久以前」和「多慢」；标题/用户/方法/路径/IP 等不提供排序
        'cost'        => 'cost',
        'create_time' => 'create_time',
    ];

    /**
     * 列表查询不返回的重字段。
     *
     * `params` / `result` 各最长 8000 字符（见中间件 TEXT_LIMIT），一页 15 行就是几百 KB，
     * 而列表页并不展示它们 —— 详情抽屉打开时再按 id 取全文（`detail()`）。
     */
    private const LIST_HIDDEN_FIELDS = 'params,result,ua';

    /**
     * 关键字搜索的默认回看天数。
     *
     * `params` / `result` 是 text 列，`LIKE '%kw%'` 无法走索引（MySQL 5.6 也没有可用的
     * 中文全文索引），不收敛时间窗就是全表扫描。用户显式给了时间范围时以用户的为准。
     */
    private const KEYWORD_DAYS = 7;

    /** 关键字最大长度：再长只会让扫描更慢，且多半是误粘贴 */
    private const KEYWORD_MAX = 100;

    /** 多行关键字最多生效行数：每行一个 LIKE，行数太多会把查询拖垮 */
    private const KEYWORD_MAX_LINES = 20;

    // ------------------------------------------------------------------
    // 写入
    // ------------------------------------------------------------------

    /**
     * 记录一条登录日志（成功与失败都记）。
     *
     * 登录接口是 `#[NoLogin]`，那时还没有用户上下文，操作日志中间件拿不到操作人，
     * 所以由登录逻辑自己记；写成 `path='/auth/login'` 的 `sys_log` 记录，与操作日志同表。
     * 写日志失败**绝不影响登录本身**（吞掉异常并记 error 日志）。
     */
    public static function recordLogin(int $userId, string $username, bool $success, string $message = ''): void
    {
        try {
            $request = function_exists('request') ? request() : null;

            // 登录链路还没有用户上下文（登录接口是 #[NoLogin]），且登录日志必须**无条件**写入
            // （含失败尝试）。原本用 `OperationLog::withoutGlobalScope()->insert()` 跳出数据权限，
            // 现在改走 `LogChain::write()`：它直接操作查询构造器，连模型层的作用域与字段规则
            // 都不经过（对审计写入更安全），同时补上链式哈希 —— 否则登录日志会成为链上的空洞，
            // 而"删掉/改掉登录失败记录"恰恰是最常见的掩盖手段。
            LogChain::write([
                'user_id'     => $userId,
                'username'    => mb_substr($username, 0, 64),
                'status'      => $success ? 1 : 0,
                'message'     => mb_substr($message, 0, 255),
                'method'      => 'POST',
                'path'        => self::LOGIN_PATH,
                'title'       => '登录',
                'status_code' => $success ? 200 : 400,
                // 登录接口不走 OperationLog，这条就是整条链里唯一的记录；
                // 三个 ID 都写，报错弹窗里的操作 ID 才能在日志页搜到
                'trace_id'    => $request === null ? '' : (string)($request->traceId ?? ''),
                'action_id'   => $request === null ? '' : (string)($request->actionId ?? ''),
                'session_id'  => $request === null ? '' : (string)($request->sessionId ?? ''),
                'ip'          => $request === null ? '' : ClientIp::resolve($request),
                'ua'          => mb_substr((string)($request?->header('user-agent', '') ?? ''), 0, 255),
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Log::error('login log failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 查询 / 导出
    // ------------------------------------------------------------------

    public static function paginate(array $params): array
    {
        $query = self::filtered($params);

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE);
        // params / result / ua 不随列表下发（见 LIST_HIDDEN_FIELDS），详情走 detail()
        $list = $query->page($page, $limit)->withoutField(self::LIST_HIDDEN_FIELDS)->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    /**
     * 单条日志全文（详情抽屉用）。
     *
     * 列表为了体积已经裁掉 `params` / `result` / `ua`，打开详情时再按 id 取一次；
     * 仍走 `newScopedQuery()`，越权与不存在的行都返回同一个 404，不泄露行是否存在。
     *
     * @return array<string,mixed>
     */
    public static function detail(int $id): array
    {
        if ($id <= 0) {
            throw new ApiException(I18n::t('log.id_invalid'), 422);
        }

        $row = OperationLog::newScopedQuery()->where('id', $id)->find();
        if ($row === null) {
            throw new ApiException(I18n::t('log.not_found'), 404);
        }

        return $row->toArray();
    }

    /**
     * 导出 CSV（按当前筛选与数据范围）。
     *
     * **一律异步**：不区分数据量，只落一条 `sys_export_task` + 投递一条作业就返回，
     * 实际生成由作业队列进程消费（不阻塞在线请求）。前端在**全局**「导出任务」面板
     * 里查看状态并下载归档文件 —— 同步下载的体验差异由「小数据量秒级完成」抵消。
     *
     * 作业投递失败**不影响导出请求成功**：任务行已落库，`ExportTaskConsumer`（cron）
     * 与「下载时补生成」两条兜底路径仍然有效，只是会慢一点。
     *
     * @return array{async:true,task_id:int,total:int}
     */
    public static function export(array $params, int $userId): array
    {
        $total = (int)self::filtered($params)->count();
        $taskId = ExportTask::create('log', $params, $userId);

        try {
            JobQueue::push(
                ExportJob::class,
                ['task_id' => $taskId],
                // 幂等键同步任务 ID：同一条导出被重复投递（重试、手工补跑）也只会生成一次
                ['queue' => 'export', 'unique' => 'export:' . $taskId, 'max_attempts' => 3]
            );
        } catch (Throwable $e) {
            Log::error('export: enqueue job failed: ' . $e->getMessage());
        }

        return ['async' => true, 'task_id' => $taskId, 'total' => $total];
    }

    /**
     * 流式把导出结果写进文件（异步任务用），返回总行数。
     *
     * 用「id 游标 + 每批 1000 行」的 keyset 分页而不是一次性 `select()->toArray()`：
     * 10 万行的导出如果整体读进内存会撞 `memory_limit`，每批 1000 行内存恒定。
     */
    public static function exportToFile(string $target, array $params): int
    {
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new ApiException(I18n::t('log.export_file_failed'), 500);
        }

        try {
            // BOM + 表头（经同一套公式注入防护）
            fwrite($handle, "\xEF\xBB\xBF");
            Csv::writeRow($handle, self::columns());

            $total  = 0;
            $lastId = 0;
            while (true) {
                $batch = self::filtered($params)
                    ->where('id', '>', $lastId)
                    ->order('id', 'asc')
                    ->limit(1000)
                    ->select()->toArray();

                if ($batch === []) {
                    break;
                }

                foreach ($batch as $row) {
                    Csv::writeRow($handle, self::formatRow($row));
                    $total++;
                }

                $lastId = (int)end($batch)['id'];
                if (count($batch) < 1000) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $total;
    }

    /** 导出列头（同步 / 异步共用，避免两处漂移） */
    private static function columns(): array
    {
        return [
            'ID', '账号', '操作', '结果', '方法', '路径', '节点',
            '操作ID', '请求ID', '会话ID', 'IP', '状态码', '耗时(ms)', '时间',
        ];
    }

    /** 一行日志 → CSV 单元格（同步 / 异步共用） */
    private static function formatRow(array $row): array
    {
        // 「操作」与「说明」合并：说明多为失败原因，跟操作名放同一格更便于按行阅读
        $title   = (string)($row['title'] ?? '');
        $message = (string)($row['message'] ?? '');

        return [
            (string)($row['id'] ?? ''),
            (string)($row['username'] ?? ''),
            $message === '' ? $title : $title . '：' . $message,
            (int)($row['status'] ?? 1) === 1 ? '成功' : '失败',
            (string)($row['method'] ?? ''),
            (string)($row['path'] ?? ''),
            (string)($row['node'] ?? ''),
            (string)($row['action_id'] ?? ''),
            (string)($row['trace_id'] ?? ''),
            (string)($row['session_id'] ?? ''),
            (string)($row['ip'] ?? ''),
            (string)($row['status_code'] ?? ''),
            (string)($row['cost'] ?? ''),
            (string)($row['create_time'] ?? ''),
        ];
    }

    /**
     * 按 `trace_id` 聚合**一次请求**的日志（请求级，报错定位用）。
     *
     * 正常只有一条（一个请求写一条日志）；保留它是为了「响应头 / 失败响应体里的
     * trace_id 能直接查到那条记录」，而不是当作操作链路用。
     *
     * 走同一份 `newScopedQuery()`：看不见的日志自然不出现（越权行不泄露）。
     * 未传 / 空 `trace_id` 抛 422，避免「不带条件」把整表捞出来。
     */
    public static function trace(string $traceId): array
    {
        $traceId = trim($traceId);
        if ($traceId === '') {
            throw new ApiException(I18n::t('log.trace_id_required'), 422);
        }

        $list = OperationLog::newScopedQuery()
            ->where('trace_id', $traceId)
            ->order('id', 'asc')
            ->limit(self::TRACE_LIMIT)
            ->select()->toArray();

        $failed = 0;
        $cost   = 0;
        foreach ($list as $row) {
            if ((int)($row['status'] ?? 1) !== 1) {
                $failed++;
            }
            $cost += (int)($row['cost'] ?? 0);
        }

        return [
            'trace_id'  => $traceId,
            'total'     => count($list),
            'failed'    => $failed,
            'cost'      => $cost,
            'truncated' => count($list) >= self::TRACE_LIMIT,
            'list'      => $list,
        ];
    }

    /**
     * 按 `action_id` 聚合**一次用户操作**的全部日志（链路视图的主视图）。
     *
     * 一次操作 = 前端标记的一个边界（进页面 / 一次提交），其间的多个请求共用
     * `action_id`。按 `id` 升序返回，即操作内的时间顺序；`session_id` 取自记录，
     * 前端可据此继续跳到「会话轨迹」。数据范围与列表、trace 完全一致。
     */
    public static function action(string $actionId): array
    {
        $actionId = trim($actionId);
        if ($actionId === '') {
            throw new ApiException(I18n::t('log.action_id_required'), 422);
        }

        $list = OperationLog::newScopedQuery()
            ->where('action_id', $actionId)
            ->order('id', 'asc')
            ->limit(self::ACTION_LIMIT)
            ->select()->toArray();

        $failed    = 0;
        $cost      = 0;
        $sessionId = '';
        foreach ($list as $row) {
            if ((int)($row['status'] ?? 1) !== 1) {
                $failed++;
            }
            $cost += (int)($row['cost'] ?? 0);
            if ($sessionId === '') {
                $sessionId = (string)($row['session_id'] ?? '');
            }
        }

        return [
            'action_id'  => $actionId,
            'session_id' => $sessionId,
            'total'      => count($list),
            'failed'     => $failed,
            'cost'       => $cost,
            'truncated'  => count($list) >= self::ACTION_LIMIT,
            'list'       => $list,
        ];
    }

    /**
     * 会话轨迹：按 `session_id` 读取一段日志（审计视角）。
     *
     * 用 `after_id` 游标而不是页码：日志会持续写入，页码分页在「加载下一页」时
     * 会重复/跳过（偏移量漂移）；游标只依赖不可变的 `id`，追加式加载不会错位。
     *
     * `total` 是**整个会话**的记录数（受数据范围约束），用于前端显示「已加载 / 总数」。
     */
    public static function session(string $sessionId, int $afterId = 0, int $limit = self::SESSION_PAGE): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new ApiException(I18n::t('log.session_id_required'), 422);
        }

        $limit = max(1, min(self::SESSION_PAGE_MAX, $limit));
        $base  = static fn () => OperationLog::newScopedQuery()->where('session_id', $sessionId);

        $total = (int)$base()->count();
        $rows  = $base()
            ->where('id', '>', max(0, $afterId))
            ->order('id', 'asc')
            ->limit($limit + 1)
            ->select()->toArray();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $last = $rows === [] ? $afterId : (int)end($rows)['id'];

        return [
            'session_id'  => $sessionId,
            'total'       => $total,
            'next_cursor' => $last,
            'has_more'    => $hasMore,
            'list'        => $rows,
        ];
    }

    /** @return \think\db\BaseQuery */
    private static function filtered(array $params)
    {
        $query = OperationLog::newScopedQuery();

        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }
        // 请求方法：列头多选，值形如 `GET,POST`
        $methods = FilterInput::values($params['method'] ?? null);
        if ($methods !== []) {
            $query->whereIn('method', $methods);
        }
        if (!empty($params['path'])) {
            $query->where('path', 'like', '%' . $params['path'] . '%');
        }
        if (!empty($params['ip'])) {
            $query->where('ip', 'like', '%' . trim((string)$params['ip']) . '%');
        }
        // 语义化筛选：按权限节点或操作名找（比记 path 直观）
        if (!empty($params['node'])) {
            $query->where('node', 'like', '%' . $params['node'] . '%');
        }
        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }
        // 结果：1 成功 / 0 失败（列头多选，值形如 `1,0`）
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        // 请求 ID：拿到一次请求的报错 trace 后可直接检索
        if (!empty($params['trace_id'])) {
            $query->where('trace_id', trim((string)$params['trace_id']));
        }
        // 操作 ID：一次用户操作（多个请求）的链路
        if (!empty($params['action_id'])) {
            $query->where('action_id', trim((string)$params['action_id']));
        }
        // 会话 ID：一次页面会话的全部轨迹
        if (!empty($params['session_id'])) {
            $query->where('session_id', trim((string)$params['session_id']));
        }
        // 操作时间范围（列头时间筛选，值已归一化为 Y-m-d H:i:s）
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);

        // 请求参数 / 返回结果：模糊匹配（text 列只能全表扫，因此单独收敛时间窗）
        $paramsKeywords = self::keywords($params['params_like'] ?? '');
        $resultKeywords = self::keywords($params['result_like'] ?? '');
        if ($paramsKeywords !== [] || $resultKeywords !== []) {
            // 用户给了时间范围就按用户的；没给则默认只看最近 KEYWORD_DAYS 天，
            // 否则 LIKE '%kw%' 会扫全表（日志表通常几十万行起步）
            if ($start === '' && $end === '') {
                $start = date('Y-m-d H:i:s', strtotime('-' . self::KEYWORD_DAYS . ' days'));
            }
            foreach ($paramsKeywords as $keyword) {
                $query->where('params', 'like', '%' . $keyword . '%');
            }
            foreach ($resultKeywords as $keyword) {
                $query->where('result', 'like', '%' . $keyword . '%');
            }
        }

        if ($start !== '') {
            $query->where('create_time', '>=', $start);
        }
        if ($end !== '') {
            $query->where('create_time', '<=', $end);
        }

        return $query;
    }

    /**
     * 多行关键字 → 每行一个 LIKE 条件（多行之间是 AND）。
     *
     * 输入框是多行的（方便粘贴 JSON 片段）：逐行匹配比把整段塞进一个 `%...%`
     * 更实用 —— 换行和缩进不会导致一个字都匹配不上。每行单独转义、限长，最多 20 行。
     *
     * think-orm 的 like 走参数绑定，不存在注入；但不转义的话用户搜 `100%`
     * 会被当成通配符，把整表都命中。
     *
     * @return string[]
     */
    private static function keywords(mixed $raw): array
    {
        $lines = preg_split('/\R/u', (string)$raw) ?: [];
        $list  = [];

        foreach ($lines as $line) {
            $line = mb_substr(trim($line), 0, self::KEYWORD_MAX);
            if ($line !== '') {
                $list[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $line);
            }
            if (count($list) >= self::KEYWORD_MAX_LINES) {
                break;
            }
        }

        return $list;
    }

    // ------------------------------------------------------------------
    // 登录安全分析（P2-13）
    // ------------------------------------------------------------------

    /** 登录分析默认回看天数 */
    private const ANALYSIS_DAYS = 7;

    /** 登录分析最多回看天数（再长意义不大，且趋势图会退化成一堆点） */
    private const ANALYSIS_MAX_DAYS = 90;

    /** 趋势的时间粒度切换点：窗口不超过该小时数按小时聚合，否则按天 */
    private const HOURLY_LIMIT_HOURS = 48;

    /** TOP 榜取前几名 */
    private const TOP_LIMIT = 10;

    /** 异地登录检测的样本上限（按最近 id 倒序取，避免把整段登录历史拉进内存） */
    private const IP_CHANGE_SAMPLE = 5000;

    /**
     * 登录安全分析：撞库 / 爆破 / 异地登录的聚合视图。
     *
     * 数据源与列表页完全一致（`path = '/auth/login'`，`status` 1 成功 / 0 失败），
     * 且走同一份 `newScopedQuery()` —— **数据范围对聚合同样生效**：
     * 「仅本人」档的管理员只看得到自己的登录记录，不会从聚合视图里越权看到全站账号。
     *
     * @param  array<string,mixed> $params days / start / end
     * @return array{
     *   range:array{start:string,end:string,granularity:string},
     *   summary:array{total:int,success:int,failed:int,fail_rate:float,users:int,ips:int},
     *   trend:array<int,array{bucket:string,success:int,failed:int}>,
     *   top_users:array<int,array{username:string,count:int}>,
     *   top_ips:array<int,array{ip:string,count:int}>,
     *   ip_changes:array<int,array{username:string,from_ip:string,to_ip:string,time:string}>
     * }
     */
    public static function loginAnalysis(array $params = []): array
    {
        [$start, $end] = self::analysisRange($params);

        $base = static fn () => OperationLog::newScopedQuery()
            ->where('path', self::LOGIN_PATH)
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end);

        // 汇总用**一条**聚合语句取全（原先 4 条 count 分开查）：
        // 窗口内数据只扫一遍，且 `COUNT(DISTINCT ...)` 写在 field 里可以避开
        // `distinct()` —— 那个方法只声明在 `think\db\Query`，不在基类 `BaseQuery` 上。
        $agg = $base()
            ->field(
                'COUNT(*) AS total,'
                . ' SUM(status = 0) AS failed,'
                . ' COUNT(DISTINCT CASE WHEN status = 0 THEN username END) AS users,'
                . " COUNT(DISTINCT CASE WHEN status = 0 AND ip <> '' THEN ip END) AS ips"
            )
            ->select()
            ->toArray();

        $total  = (int)($agg[0]['total'] ?? 0);
        $failed = (int)($agg[0]['failed'] ?? 0);
        $users  = (int)($agg[0]['users'] ?? 0);
        $ips    = (int)($agg[0]['ips'] ?? 0);

        return [
            'range' => [
                'start'       => $start,
                'end'         => $end,
                'granularity' => self::granularity($start, $end),
            ],
            'summary' => [
                'total'     => $total,
                'success'   => $total - $failed,
                'failed'    => $failed,
                'fail_rate' => $total > 0 ? round($failed / $total * 100, 2) : 0.0,
                'users'     => $users,
                'ips'       => $ips,
            ],
            'trend'      => self::loginTrend($start, $end),
            'top_users'  => self::loginTop($base(), 'username'),
            'top_ips'    => self::loginTop($base(), 'ip'),
            'ip_changes' => self::ipChanges($start, $end),
        ];
    }

    /**
     * 统计窗口：默认回看 `ANALYSIS_DAYS` 天，可用 `days` 覆盖，也可用 `start` / `end` 精确指定。
     *
     * @param  array<string,mixed> $params
     * @return array{0:string,1:string}
     */
    private static function analysisRange(array $params): array
    {
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);

        if ($start === '' && $end === '') {
            $days  = (int)($params['days'] ?? self::ANALYSIS_DAYS);
            $days  = max(1, min(self::ANALYSIS_MAX_DAYS, $days));
            $start = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        }
        if ($start === '') {
            $start = date('Y-m-d H:i:s', strtotime((string)$end . ' -' . self::ANALYSIS_DAYS . ' days'));
        }
        if ($end === '') {
            $end = date('Y-m-d H:i:s');
        }

        return [$start, $end];
    }

    /** 按小时或按天聚合（窗口 <= 48 小时用小时，否则用天） */
    private static function granularity(string $start, string $end): string
    {
        $hours = (strtotime($end) - strtotime($start)) / 3600;

        return $hours <= self::HOURLY_LIMIT_HOURS ? 'hour' : 'day';
    }

    /**
     * 成功 / 失败趋势。
     *
     * 用 `DATE_FORMAT` 在库里分桶（而不是把整段日志拉进 PHP），并**补齐空桶**：
     * 否则「某天没有任何登录」会在图上表现为直接跳过去，看不出异常。
     *
     * @return array<int,array{bucket:string,success:int,failed:int}>
     */
    private static function loginTrend(string $start, string $end): array
    {
        $granularity = self::granularity($start, $end);
        $format      = $granularity === 'hour' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';

        // 运行期实例是 `think\db\Query`，但 `newScopedQuery()` 声明的返回类型是基类
        // `BaseQuery`（只声明了公共子集）—— `group()` 只存在于具体类上，这里显式收窄，
        // 而不是把共享方法的签名改宽。
        /** @var \think\db\Query $trendQuery */
        $trendQuery = OperationLog::newScopedQuery();

        $rows = $trendQuery
            ->where('path', self::LOGIN_PATH)
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->field("DATE_FORMAT(create_time, '{$format}') AS bucket, status, COUNT(*) AS num")
            ->group('bucket, status')
            ->select()
            ->toArray();

        $buckets = [];
        foreach ($rows as $row) {
            $bucket = (string)$row['bucket'];
            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = ['bucket' => $bucket, 'success' => 0, 'failed' => 0];
            }
            // status：1 成功 / 0 失败
            if ((int)$row['status'] === 1) {
                $buckets[$bucket]['success'] += (int)$row['num'];
            } else {
                $buckets[$bucket]['failed'] += (int)$row['num'];
            }
        }

        // 补空桶：从窗口起点按粒度一路补到终点
        $out    = [];
        $cursor = strtotime($granularity === 'hour' ? date('Y-m-d H:00', strtotime($start)) : date('Y-m-d', strtotime($start)));
        $last   = strtotime($end);
        $step   = $granularity === 'hour' ? 3600 : 86400;
        $guard  = 0;

        while ($cursor <= $last && $guard < 4000) {
            $key   = date($granularity === 'hour' ? 'Y-m-d H:00' : 'Y-m-d', $cursor);
            $out[] = $buckets[$key] ?? ['bucket' => $key, 'success' => 0, 'failed' => 0];
            $cursor += $step;
            $guard++;
        }

        // 窗口外的桶（数据里存在但不在补齐区间内）也一并带上，避免丢数据
        foreach ($buckets as $key => $row) {
            if (!in_array($key, array_column($out, 'bucket'), true)) {
                $out[] = $row;
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['bucket'], $b['bucket']));

        return $out;
    }

    /**
     * 失败 TOP N（用户名 / IP 共用）。
     *
     * @param  \think\db\BaseQuery $query 已限定 path 与窗口的查询
     * @return array<int,array<string,mixed>>
     */
    private static function loginTop($query, string $field): array
    {
        // 收窄成具体类：`group()` 只声明在 `think\db\Query` 上（它继承 `BaseQuery`），
        // 而 `newScopedQuery()` 声明的返回类型是基类。运行期实例就是 `Query`。
        /** @var \think\db\Query $grouped */
        $grouped = $query
            ->where('status', 0)
            ->where($field, '<>', '')
            ->field("{$field} AS name, COUNT(*) AS num");

        $rows = $grouped
            ->group($field)
            ->order('num', 'desc')
            ->limit(self::TOP_LIMIT)
            ->select()
            ->toArray();

        return array_map(
            static fn (array $row): array => [
                $field === 'ip' ? 'ip' : 'username' => (string)$row['name'],
                'count'                            => (int)$row['num'],
            ],
            $rows
        );
    }

    /**
     * 异地登录：同一账号的两次**成功**登录之间 IP 发生了变化。
     *
     * 按 id 升序取样本后逐账号比较相邻两次的 IP（忽略空 IP）：只看「与上一次是否相同」，
     * 因此一个账号来回切换网络会被记多次 —— 这正是想看到的信号，不做去重合并。
     *
     * @return array<int,array{username:string,from_ip:string,to_ip:string,time:string}>
     */
    private static function ipChanges(string $start, string $end): array
    {
        $rows = OperationLog::newScopedQuery()
            ->where('path', self::LOGIN_PATH)
            ->where('status', 1)
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->field('id, username, ip, create_time')
            ->order('id', 'desc')
            ->limit(self::IP_CHANGE_SAMPLE)
            ->select()
            ->toArray();

        // 取样是「最近的 N 条」，比较必须按时间正序
        usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

        $lastIp = [];
        $out    = [];
        foreach ($rows as $row) {
            $username = (string)$row['username'];
            $ip       = trim((string)$row['ip']);
            if ($username === '' || $ip === '') {
                continue;
            }

            if (isset($lastIp[$username]) && $lastIp[$username] !== $ip) {
                $out[] = [
                    'username' => $username,
                    'from_ip'  => $lastIp[$username],
                    'to_ip'    => $ip,
                    'time'     => (string)$row['create_time'],
                ];
            }
            $lastIp[$username] = $ip;
        }

        // 最新的变化排前面
        return array_slice(array_reverse($out), 0, self::TOP_LIMIT);
    }

    public static function delete(array $ids): void
    {
        if ($ids) {
            // 带作用域：只能删掉自己看得到的日志
            OperationLog::newScopedQuery()->whereIn('id', array_map('intval', $ids))->delete();
            // 审计链是"只增"结构，删除必然打断链接。应用自己删的这类删除
            // 由 reanchor() 把校验起点移到幸存的第一行，避免后续校验把它当成篡改；
            // 链**中间**的删除仍会被 `cccms:log-verify` 作为断点报出（无法与篡改区分）。
            LogChain::reanchor();
        }
    }

    public static function clear(): void
    {
        // 同理：清空的是「当前用户可见范围」内的日志，而不是全表
        OperationLog::newScopedQuery()->where('id', '>', 0)->delete();
        LogChain::reanchor();
    }
}
