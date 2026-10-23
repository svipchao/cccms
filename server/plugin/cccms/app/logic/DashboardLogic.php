<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Crontab;
use plugin\cccms\app\model\DataRule;
use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\DictType;
use plugin\cccms\app\model\File;
use plugin\cccms\app\model\Menu;
use plugin\cccms\app\model\OperationLog;
use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\Tenant;
use plugin\cccms\app\model\User;
use plugin\cccms\support\UserContext;
use think\facade\Db;

/**
 * 工作台统计。
 *
 * 只返回**聚合数字**（计数、按天趋势、类型分布），不含任何业务明细行，
 * 因此可以安全地对「任意已登录用户」开放（见 DashboardController 的 #[NoAuth]）。
 *
 * 权限只有两层，且都以「当前账号能不能进对应列表页」为准：
 * - **超管**：`UserContext::hasPermission()` 直通，因此拿到全部统计；
 * - **其他账号**：只拿到自己持有权限节点的模块计数，其余键**不下发**，
 *   前端据此不渲染卡片 —— 否则「进不去列表页，却能从工作台读出该模块的数据量」。
 *
 * 数字口径与各自列表页保持一致：全部走模型查询，参与数据权限的表
 * （`user` / `dept` / `file` / `log`）自动按当前用户的范围统计 ——
 * 否则「列表只能看到 3 个附件、工作台却显示 1000」等于绕开列表把总量漏出去。
 */
final class DashboardLogic
{
    /** 趋势统计的天数 */
    private const TREND_DAYS = 7;

    /** 文件类型分布取前 N 个 */
    private const FILE_TYPE_LIMIT = 6;

    /** 统计项 → 「能进对应列表页」所需的权限节点（无权限则不返回该计数） */
    private const CARD_PERMISSIONS = [
        'user'      => 'cccms:user:index',
        'role'      => 'cccms:role:index',
        'dept'      => 'cccms:dept:tree',
        'post'      => 'cccms:post:index',
        'menu'      => 'cccms:menu:tree',
        'file'      => 'cccms:file:index',
        'log'       => 'cccms:log:index',
        'today_log' => 'cccms:log:index',
        'online'    => 'cccms:online:index',
        'dict'      => 'cccms:dict:index',
        'crontab'   => 'cccms:crontab:index',
        'data_rule' => 'cccms:data_rule:index',
        'tenant'    => 'cccms:tenant:index',
    ];

    public static function stats(UserContext $user): array
    {
        $canLog  = $user->hasPermission(self::CARD_PERMISSIONS['log']);
        $canFile = $user->hasPermission(self::CARD_PERMISSIONS['file']);

        return [
            'counts'     => self::counts($user),
            // 图表同样按权限下发：拿不到日志 / 附件列表页的账号，这里给空数据而不是数字
            'log_trend'  => $canLog ? self::logTrend(self::TREND_DAYS) : ['dates' => [], 'values' => []],
            'file_types' => $canFile ? self::fileTypes(self::FILE_TYPE_LIMIT) : [],
        ];
    }

    /**
     * 关键业务对象的数量（多数只统计启用中的），逐项按权限节点下发。
     *
     * 性能：13 个计数合成**一条 SQL**（各模型查询先 `fetchSql(true)` 拿到带作用域的
     * 子查询，再拼成 `SELECT (子查询) AS 键, ...`）。
     * 为什么不是缓存：工作台是登录后的默认落地页，「刚加完用户回来数字没变」比
     * 多一次聚合查询更让人困惑；合成一条后往返次数从 13+ 降到 1，且没有任何延迟生效。
     * 为什么不是并发：Webman 进程内单请求同步执行，PHP 侧没有并行查询能力。
     */
    private static function counts(UserContext $user): array
    {
        $today = date('Y-m-d');

        /** @var array<string,callable():string> $subQueries 返回标量子查询 SQL */
        $subQueries = [
            'user'      => static fn (): string => (string)User::where('status', 1)->fetchSql(true)->count(),
            'role'      => static fn (): string => (string)Role::where('status', 1)->fetchSql(true)->count(),
            'dept'      => static fn (): string => (string)Dept::where('status', 1)->fetchSql(true)->count(),
            'post'      => static fn (): string => (string)Post::where('status', 1)->fetchSql(true)->count(),
            'menu'      => static fn (): string => (string)Menu::where('type', 2)->fetchSql(true)->count(),
            'file'      => static fn (): string => (string)File::newScopedQuery()->fetchSql(true)->count(),
            'log'       => static fn (): string => (string)OperationLog::newScopedQuery()->fetchSql(true)->count(),
            'today_log' => static fn (): string => (string)OperationLog::newScopedQuery()
                ->whereBetween('create_time', [$today . ' 00:00:00', $today . ' 23:59:59'])
                ->fetchSql(true)
                ->count(),
            'dict'      => static fn (): string => (string)DictType::where('status', 1)->fetchSql(true)->count(),
            'crontab'   => static fn (): string => (string)Crontab::where('status', 1)->fetchSql(true)->count(),
            'data_rule' => static fn (): string => (string)DataRule::fetchSql(true)->count(),
            'tenant'    => static fn (): string => (string)Tenant::fetchSql(true)->count(),
        ];

        $allowed = [];
        foreach ($subQueries as $key => $build) {
            if ($user->hasPermission(self::CARD_PERMISSIONS[$key])) {
                $allowed[$key] = $build;
            }
        }

        $counts = self::fetchCounts($allowed);

        // 在线人数走 Redis（不是 SQL），单独取；取不到按 0 处理
        if ($user->hasPermission(self::CARD_PERMISSIONS['online'])) {
            $counts['online'] = self::safe(static fn (): int => OnlineLogic::count());
        }

        // 我的未读消息：阅读侧对「登录即可」，与权限节点无关
        $counts['notice_unread'] = self::safe(static fn (): int => NoticeLogic::unreadCount($user->id));

        return $counts;
    }

    /**
     * 把多个计数子查询合成一次聚合查询。
     *
     * 失败（某条子查询语法不被当前数据库接受等）时退化为逐项统计：
     * 工作台是落地页，宁可多几次往返也不能整体 500。
     *
     * @param array<string,callable():string> $builders
     * @return array<string,int>
     */
    private static function fetchCounts(array $builders): array
    {
        if ($builders === []) {
            return [];
        }

        try {
            $selects = [];
            foreach ($builders as $key => $build) {
                $sql = trim($build());
                if ($sql === '') {
                    continue;
                }
                // 子查询里的表名由模型/查询构造器拼好（含租户与数据权限条件），这里只做包裹
                $selects[] = '(' . $sql . ') AS `' . $key . '`';
            }

            if ($selects === []) {
                return [];
            }

            $rows = Db::query('SELECT ' . implode(', ', $selects));
            $row  = is_array($rows) && isset($rows[0]) ? (array)$rows[0] : [];

            $counts = [];
            foreach (array_keys($builders) as $key) {
                if (array_key_exists($key, $row)) {
                    $counts[$key] = (int)$row[$key];
                }
            }

            return $counts;
        } catch (\Throwable) {
            $counts = [];
            foreach ($builders as $key => $build) {
                $counts[$key] = self::safe(
                    static fn (): int => (int)(Db::query('SELECT (' . $build() . ') AS `cnt`')[0]['cnt'] ?? 0)
                );
            }

            return $counts;
        }
    }

    /**
     * 容错取值：在线用户数走 Redis，抖动或未启用时退化为 0。
     *
     * 工作台是登录后的默认落地页，不能因为一个附属统计项取不到就整体 500。
     */
    private static function safe(callable $counter): int
    {
        try {
            return (int)$counter();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 最近 N 天的操作日志数量。
     *
     * 一条 GROUP BY 在库内分桶，再在 PHP 补空桶（保证「没有数据的日期」也补 0，
     * 前端折线图不会出现断点）—— 避免逐天 count 带来的 N 次查询。
     */
    private static function logTrend(int $days): array
    {
        $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' day'));
        $end   = date('Y-m-d 23:59:59');

        /** @var \think\db\Query $trendQuery */
        $trendQuery = OperationLog::newScopedQuery();
        $rows = $trendQuery
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->field("DATE_FORMAT(create_time, '%Y-%m-%d') AS day, COUNT(*) AS num")
            ->group('day')
            ->select()
            ->toArray();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string)$row['day']] = (int)$row['num'];
        }

        $dates  = [];
        $values = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day     = date('Y-m-d', strtotime("-{$i} day"));
            $dates[] = $day;
            $values[] = $byDay[$day] ?? 0;
        }

        return ['dates' => $dates, 'values' => $values];
    }

    /** 附件扩展名分布（饼图用） */
    private static function fileTypes(int $limit): array
    {
        $rows = File::newScopedQuery()
            ->field('ext, COUNT(*) AS num')
            ->group('ext')
            ->order('num', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(
            static fn (array $row): array => [
                'name'  => (string)($row['ext'] ?: '其他'),
                'value' => (int)$row['num'],
            ],
            $rows
        );
    }
}
