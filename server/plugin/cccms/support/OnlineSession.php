<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Redis;
use Throwable;

/**
 * 在线会话登记表（供「在线用户 / 强制下线」使用）。
 *
 * JWT 无状态，这里用 Redis 维护辅助索引：
 *   - `cccms:online:s:{jti}` Hash：用户、IP、UA、登录时间、最后活跃、过期时间；
 *   - `cccms:online:index` ZSet：member = jti，score = 最后活跃时间。
 *
 * 取舍：不维护「用户 → jti」集合（扫描索引更不容易失配）；`touch()` 有 60 秒节流，
 * 同时兼作自愈入口（通过鉴权的请求本身即证明会话有效）；索引 ZSet 不设 TTL，
 * 过期成员由列表查询与 `prune()` 清理；Redis 不可用时全部静默降级。
 *
 * 列表分两条路径：无关键字走 Redis 服务端分页（zRevRange + zCard，不拉全量）；
 * 有关键字则分段扫描（SCAN_BATCH）后应用层过滤、内存分页。
 */
final class OnlineSession
{
    private const SESSION_PREFIX = 'cccms:online:s:';
    private const INDEX_KEY      = 'cccms:online:index';

    /** 活跃时间刷新间隔（秒）：小于该间隔的请求不写 Redis */
    private const TOUCH_INTERVAL = 60;

    /** 分段扫描批大小：有关键字过滤 / 清理 / 下线时按这个粒度拉取，避免一次性拉全量 */
    private const SCAN_BATCH = 200;

    /** 支持列头筛选的文本字段（会话 Hash 里可直接做包含匹配） */
    private const FILTER_FIELDS = ['username', 'ip'];

    /**
     * 列头可排序列：前端 prop => `decorate()` 之后的字段名。
     *
     * 会话数据在 Redis Hash 里，没有 SQL 可排，只能在内存里排；
     * 因此排序请求会走「全量扫描 + 排序 + 切片」，未指定排序时仍走 Redis 服务端分页。
     */
    private const SORTABLE = [
        // 在线会话只留「登录时间 / 最后活跃」：用户名、IP、设备等排序没有排障价值
        'login_at' => 'login_at',
        'last_at'  => 'last_at',
    ];

    /**
     * 支持列头筛选的时间字段：入参前缀 => 会话 Hash 里的时间戳字段。
     *
     * 入参形如 `login_start` / `login_end`，比较前统一归一成 Unix 时间戳
     * （Hash 里存的是时间戳，展示时才格式化成字符串）。
     */
    private const RANGE_FIELDS = [
        'login'  => 'login_at',
        'active' => 'last_at',
        'expire' => 'exp',
    ];

    /**
     * 登录成功后登记会话。
     */
    public static function register(
        int $userId,
        string $username,
        string $nickname,
        string $jti,
        string $ip = '',
        string $ua = '',
    ): void {
        if ($jti === '' || $userId <= 0) {
            return;
        }

        $now = time();

        self::writeRow($jti, $userId, $username, $nickname, $ip, $ua, $now, $now + TokenService::ttl());
    }

    /**
     * 刷新活跃时间（带 60 秒节流）；会话不存在时**按令牌声明自愈登记**。
     *
     * 为什么需要自愈：登记只发生在登录那一刻，令牌一旦在「登记时 Redis 抖动 / 服务重启 /
     * 本功能上线前签发」的情况下继续被使用，这条会话就永远进不了在线列表，而且
     * `rotate()` 也刻意不补建（见其注释）—— 用户明明在线，列表却是空的。
     * 一个通过鉴权的请求本身就证明会话有效，用令牌的 `iat` / `exp` 回填最贴近事实：
     * 展示的登录时间是真实签发时间，而不是「首次被发现的时刻」。
     *
     * 会话已存在时行为不变：只刷新活跃时间与 Hash 存活期，**不**延长令牌过期时间
     * （`exp` 是签发时固定的）。
     *
     * @param UserContext|null $user 当前登录用户；为 null 时不做自愈（保持旧调用方语义）
     * @param int              $issuedAt  令牌签发时间（`iat`），自愈时作为登录时间
     * @param int              $expiresAt 令牌过期时间（`exp`），自愈时作为会话过期时间
     */
    public static function touch(
        string $jti,
        ?UserContext $user = null,
        string $ip = '',
        string $ua = '',
        int $issuedAt = 0,
        int $expiresAt = 0,
    ): void {
        if ($jti === '') {
            return;
        }

        try {
            $key  = self::SESSION_PREFIX . $jti;
            $last = Redis::hGet($key, 'last_at');

            if ($last === false || $last === null) {
                // 会话缺失：自愈回填（写入是幂等的，并发请求重复写同一行无害）
                if ($user !== null && $user->id > 0) {
                    $now = time();
                    self::writeRow(
                        $jti,
                        $user->id,
                        $user->username,
                        $user->nickname,
                        $ip,
                        $ua,
                        $issuedAt > 0 ? $issuedAt : $now,
                        $expiresAt > 0 ? $expiresAt : $now + TokenService::ttl(),
                    );
                }

                return;
            }

            $now = time();
            if ($now - (int)$last < self::TOUCH_INTERVAL) {
                return;
            }

            Redis::hSet($key, 'last_at', (string)$now);
            Redis::expire($key, TokenService::ttl());
            Redis::zAdd(self::INDEX_KEY, $now, $jti);
        } catch (Throwable) {
            // 降级
        }
    }

    /** 注销单条会话（登出时调用） */
    public static function remove(string $jti): void
    {
        if ($jti === '') {
            return;
        }

        try {
            Redis::del(self::SESSION_PREFIX . $jti);
            Redis::zRem(self::INDEX_KEY, $jti);
        } catch (Throwable) {
            // 降级
        }
    }

    /**
     * 滑动续期：把一条在线会话迁到新的 jti（保留登录时间与终端信息）。
     *
     * 续期后令牌的 jti 变了，而在线列表与「强制下线」都以 jti 为准 —— 不迁移的话，
     * 管理员在列表里踢掉的是**已经废弃的旧令牌**，用户手里的新令牌依然有效。
     *
     * 会话不存在（未登记 / Redis 不可用）时静默跳过，**不新建**：登录时登记是唯一入口。
     */
    public static function rotate(string $oldJti, string $newJti, int $exp): void
    {
        if ($oldJti === '' || $newJti === '' || $oldJti === $newJti) {
            return;
        }

        try {
            $key = self::SESSION_PREFIX . $oldJti;
            $row = Redis::hGetAll($key);
            if (!is_array($row) || $row === []) {
                return;
            }

            $now = time();
            $row['jti']     = $newJti;
            $row['last_at'] = (string)$now;
            $row['exp']     = (string)$exp;

            $newKey = self::SESSION_PREFIX . $newJti;
            Redis::hMSet($newKey, $row);
            Redis::expire($newKey, max(1, $exp - $now));
            Redis::zAdd(self::INDEX_KEY, $now, $newJti);

            Redis::del($key);
            Redis::zRem(self::INDEX_KEY, $oldJti);
        } catch (Throwable) {
            // 降级：不迁移。旧会话仍在，最坏是列表里短暂显示旧令牌
        }
    }

    /** 单条会话的令牌过期时间；0 = 会话不存在或无法读取 */
    public static function expiryOf(string $jti): int
    {
        if ($jti === '') {
            return 0;
        }

        try {
            $exp = Redis::hGet(self::SESSION_PREFIX . $jti, 'exp');
            return is_numeric($exp) ? (int)$exp : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 某个用户的全部在线会话（按最后活跃时间倒序）。
     *
     * 供「我的登录设备」自助管理使用：只返回该用户的会话，不暴露他人。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listOfUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $list = [];
        self::scanAll(static function (string $jti, array $row) use ($userId, &$list): void {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $list[] = self::decorate($jti, $row);
            }
        });

        return $list;
    }

    /** 单条会话归属的用户 ID；0 = 会话不存在或无法读取 */
    public static function ownerOf(string $jti): int
    {
        if ($jti === '') {
            return 0;
        }

        try {
            $userId = Redis::hGet(self::SESSION_PREFIX . $jti, 'user_id');
            return is_numeric($userId) ? (int)$userId : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 某用户全部在线会话的最晚令牌过期时间；0 = 无在线会话。
     *
     * 供「强制下线」精确设置用户级黑名单 TTL：分界线只需保留到最后一个旧令牌过期。
     */
    public static function maxExpiryOfUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $max = 0;
        self::scanAll(static function (string $jti, array $row) use ($userId, &$max): void {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $max = max($max, (int)($row['exp'] ?? 0));
            }
        });

        return $max;
    }

    /**
     * 移除某个用户的全部在线会话。
     *
     * 注意：本方法只负责「从在线列表移除」，真正的**令牌作废**由调用方
     * 配合 `TokenBlacklist::revokeUser()` 完成 —— 两者互补，缺一不可。
     *
     * @return int 移除的会话数
     */
    public static function removeUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        // 先收集再删除：遍历过程中删除成员会让 ZSet 的 offset 偏移，导致漏扫
        $toRemove = [];
        self::scanAll(static function (string $jti, array $row) use ($userId, &$toRemove): void {
            if ((int)($row['user_id'] ?? 0) === $userId) {
                $toRemove[] = $jti;
            }
        });

        foreach ($toRemove as $jti) {
            try {
                Redis::del(self::SESSION_PREFIX . $jti);
                Redis::zRem(self::INDEX_KEY, $jti);
            } catch (Throwable) {
                // 忽略单条失败，继续处理其余会话
            }
        }

        return count($toRemove);
    }

    /**
     * 分页查询在线会话（按最后活跃时间倒序）。
     *
     * 列头筛选按字段独立生效（username / ip 包含匹配，登录 / 活跃 / 过期时间落在区间内），
     * 多列之间是 AND。无筛选时走 Redis 服务端分页；有筛选时只能分段扫描 + 应用层过滤
     * ——字段在 Hash 里，无法用 ZSet 建索引。
     *
     * @param array<string,string> $filters
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public static function paginate(
        array $filters = [],
        int $page = 1,
        int $limit = 15,
        string $sort = '',
        string $order = '',
    ): array {
        $page  = max(1, $page);
        $limit = max(1, $limit);

        $filters = self::normalizeFilters($filters);

        // 指定了可排序列：内存排序（Redis 无法按 Hash 字段排序）
        $field = self::SORTABLE[$sort] ?? '';
        if ($field !== '') {
            return self::paginateSorted($filters, $page, $limit, $field, $order === 'asc' ? 'asc' : 'desc');
        }

        if ($filters['text'] === [] && $filters['ranges'] === []) {
            return self::paginatePlain($page, $limit);
        }

        return self::paginateFiltered($filters, $page, $limit);
    }

    /**
     * 按指定列排序：全量扫描 → 过滤 → 排序 → 切片。
     *
     * 在线会话量级有限（且 `SCAN_BATCH` 分段拉取，不会一次性读全量），
     * 用内存排序换取「所有列都能排」是划算的；未指定排序时仍走服务端分页。
     *
     * @param array{text:array<string,string>,ranges:array<string,array{0:int,1:int}>} $filters
     */
    private static function paginateSorted(
        array $filters,
        int $page,
        int $limit,
        string $field,
        string $dir,
    ): array {
        $matched = [];
        $orphans = self::scanAll(static function (string $jti, array $row) use ($filters, &$matched): void {
            if (self::match($row, $filters)) {
                $matched[] = self::decorate($jti, $row);
            }
        });
        self::dropOrphans($orphans);

        usort($matched, static function (array $left, array $right) use ($field, $dir): int {
            $a = $left[$field] ?? '';
            $b = $right[$field] ?? '';
            $cmp = is_numeric($a) && is_numeric($b)
                ? ((float)$a <=> (float)$b)
                : strcmp((string)$a, (string)$b);

            return $dir === 'asc' ? $cmp : -$cmp;
        });

        return ['total' => count($matched), 'list' => array_slice($matched, ($page - 1) * $limit, $limit)];
    }

    /**
     * 归一化列头筛选：文本字段去掉空值，时间字段归一成 Unix 时间戳区间。
     *
     * @param array<string,mixed> $filters
     * @return array{text:array<string,string>,ranges:array<string,array{0:int,1:int}>}
     */
    private static function normalizeFilters(array $filters): array
    {
        $text = [];
        foreach (self::FILTER_FIELDS as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $text[$field] = $value;
            }
        }

        $ranges = [];
        foreach (self::RANGE_FIELDS as $prefix => $hashKey) {
            $from = self::timestamp($filters[$prefix . '_start'] ?? null, false);
            $to   = self::timestamp($filters[$prefix . '_end'] ?? null, true);
            if ($from > 0 || $to > 0) {
                $ranges[$hashKey] = [$from, $to];
            }
        }

        return ['text' => $text, 'ranges' => $ranges];
    }

    /**
     * 时间范围入参 → Unix 时间戳；空值 / 解析失败返回 0（表示该侧不限制）。
     *
     * 前端下发 `Y-m-d H:i:s`（选择器统一带时分秒），这里兼容纯日期写法：
     * 只给到日期时，起始补 00:00:00、结束补 23:59:59，保证按天筛选覆盖当天全天。
     */
    private static function timestamp(mixed $raw, bool $isEnd): int
    {
        $value = trim((string)($raw ?? ''));
        if ($value === '') {
            return 0;
        }

        if (preg_match('/\d{1,2}:\d{2}/', $value) !== 1) {
            $value .= $isEnd ? ' 23:59:59' : ' 00:00:00';
        }

        $parsed = strtotime($value);

        return $parsed === false ? 0 : $parsed;
    }

    /** 在线会话总数（近似：含少量待清理的过期索引，由 `prune()` 定期收敛） */
    public static function count(): int
    {
        try {
            return (int)Redis::zCard(self::INDEX_KEY);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 去重后的在线用户数：同一账号多端登录只算 1 个。
     *
     * 与 `count()`（会话数）不同，工作台「在线用户」卡片要的是**人数**不是会话数。
     * 需要扫一遍会话明细取 `user_id` 去重 —— 在线量级有限，全量扫描可接受；
     * 僵尸会话（明细已过期、索引残留）不计入，并顺手摘掉索引里的残留成员。
     */
    public static function countUsers(): int
    {
        $users   = [];
        $orphans = self::scanAll(static function (string $jti, array $row) use (&$users): void {
            $userId = (int)($row['user_id'] ?? 0);
            if ($userId > 0) {
                $users[$userId] = true;
            }
        });
        self::dropOrphans($orphans);

        return count($users);
    }

    /**
     * 清理已过期（明细键已消失）的索引成员。
     *
     * @return int 清理条数
     */
    public static function prune(): int
    {
        $toRemove = [];
        $start    = 0;

        while (true) {
            try {
                $members = Redis::zRange(self::INDEX_KEY, $start, $start + self::SCAN_BATCH - 1);
            } catch (Throwable) {
                break;
            }

            if (!is_array($members) || $members === []) {
                break;
            }

            foreach ($members as $jti) {
                $jti = (string)$jti;
                try {
                    if (!Redis::exists(self::SESSION_PREFIX . $jti)) {
                        $toRemove[] = $jti;
                    }
                } catch (Throwable) {
                    break 2;
                }
            }

            if (count($members) < self::SCAN_BATCH) {
                break;
            }
            $start += self::SCAN_BATCH;
        }

        foreach ($toRemove as $jti) {
            try {
                Redis::zRem(self::INDEX_KEY, $jti);
            } catch (Throwable) {
                // 忽略
            }
        }

        return count($toRemove);
    }

    // ------------------------------------------------------------------
    // 私有
    // ------------------------------------------------------------------

    /** 无关键字：Redis 服务端分页。命中本页的过期成员顺手从索引摘掉。 */
    private static function paginatePlain(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        try {
            $total   = (int)Redis::zCard(self::INDEX_KEY);
            $members = Redis::zRevRange(self::INDEX_KEY, $offset, $offset + $limit - 1);
        } catch (Throwable) {
            return ['total' => 0, 'list' => []];
        }

        if (!is_array($members) || $members === []) {
            return ['total' => $total, 'list' => []];
        }

        [$list, $orphans] = self::hydrate($members);
        // 摘掉本页读到的僵尸成员，并从总数里扣掉，避免「共 N 条」比列表实际行数多
        $total = max(0, $total - self::dropOrphans($orphans));

        return ['total' => $total, 'list' => $list];
    }

    /** 有筛选：分段扫描 + 应用层过滤（字段在 Hash 里，无法用 ZSet 建索引），顺手清僵尸索引。 */
    private static function paginateFiltered(array $filters, int $page, int $limit): array
    {
        $offset  = ($page - 1) * $limit;
        $matched = [];

        $orphans = self::scanAll(static function (string $jti, array $row) use ($filters, &$matched): void {
            if (self::match($row, $filters)) {
                $matched[] = self::decorate($jti, $row);
            }
        });
        self::dropOrphans($orphans);

        $total = count($matched);

        return ['total' => $total, 'list' => array_slice($matched, $offset, $limit)];
    }

    /**
     * 分段遍历全部索引成员（按活跃倒序），对每条**存在**的会话回调。
     *
     * 读到已过期的会话（Hash 已消失）时只记录、不删除 —— 遍历过程中删除成员
     * 会改变 ZSet 的 offset，导致漏扫；清理由调用方拿返回值统一执行。
     *
     * @param callable(string,array<string,mixed>):void $visitor
     * @return array<int,string> 本次发现的僵尸 jti（明细已过期，索引待摘）
     */
    private static function scanAll(callable $visitor): array
    {
        $start   = 0;
        $orphans = [];

        while (true) {
            try {
                $members = Redis::zRevRange(self::INDEX_KEY, $start, $start + self::SCAN_BATCH - 1);
            } catch (Throwable) {
                return $orphans;
            }

            if (!is_array($members) || $members === []) {
                return $orphans;
            }

            foreach ($members as $jti) {
                $jti = (string)$jti;
                $row = self::rawRow($jti);

                // 读取失败（null）无法判断是否过期：跳过且**不**清理
                if ($row === null) {
                    continue;
                }
                if ($row === []) {
                    $orphans[] = $jti;
                    continue;
                }

                $visitor($jti, $row);
            }

            if (count($members) < self::SCAN_BATCH) {
                return $orphans;
            }
            $start += self::SCAN_BATCH;
        }
    }

    /**
     * 一批 jti → [已格式化列表, 僵尸 jti]。
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
     */
    private static function hydrate(array $jtis): array
    {
        $list    = [];
        $orphans = [];

        foreach ($jtis as $jti) {
            $jti = (string)$jti;
            $row = self::rawRow($jti);

            // 读取失败（null）：当页跳过但**不**清理，避免 Redis 抖动时误删活跃成员
            if ($row === null) {
                continue;
            }

            // 明细已过期（空 Hash）：索引里是僵尸成员，交给调用方顺手摘掉
            if ($row === []) {
                $orphans[] = $jti;
                continue;
            }

            $list[] = self::decorate($jti, $row);
        }

        return [$list, $orphans];
    }

    /**
     * 写入一条会话明细，并把 jti 挂到活跃索引上。
     *
     * 登录登记（`register`）与自愈回填（`touch`）共用：两者只是 `login_at` / `exp` 的来源不同
     * —— 前者取「此刻」，后者取令牌声明里的 `iat` / `exp`。
     *
     * @param int $loginAt   会话签发时间
     * @param int $expiresAt 令牌过期时间
     */
    private static function writeRow(
        string $jti,
        int $userId,
        string $username,
        string $nickname,
        string $ip,
        string $ua,
        int $loginAt,
        int $expiresAt,
    ): void {
        if ($jti === '' || $userId <= 0) {
            return;
        }

        $now = time();
        // 令牌已过期就没有登记的意义：写进去也会立刻被 TTL 清掉
        if ($expiresAt <= $now) {
            return;
        }

        try {
            $key = self::SESSION_PREFIX . $jti;
            Redis::hMSet($key, [
                'user_id'  => (string)$userId,
                'username' => $username,
                'nickname' => $nickname,
                'jti'      => $jti,
                'ip'       => $ip,
                'ua'       => mb_substr($ua, 0, 255),
                'login_at' => (string)$loginAt,
                'last_at'  => (string)$now,
                // 令牌过期时间：强下线黑名单据此精确计算 TTL，不再按整个有效期兜底
                'exp'      => (string)$expiresAt,
            ]);
            // Hash 存活期跟着令牌剩余有效期走，不让会话明细比令牌活得久
            Redis::expire($key, $expiresAt - $now);
            Redis::zAdd(self::INDEX_KEY, $now, $jti);
        } catch (Throwable) {
            // 降级：不登记
        }
    }

    /**
     * 读取单条会话明细。
     *
     * 区分两种「读不到」，调用方据此决定能否清理索引：
     *   - `null` —— Redis 异常，无法判断，**不可**当作过期；
     *   - `[]`   —— 明细键已消失（TTL 到期），索引里是僵尸成员。
     *
     * @return array<string,mixed>|null
     */
    private static function rawRow(string $jti): ?array
    {
        try {
            $row = Redis::hGetAll(self::SESSION_PREFIX . $jti);
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : [];
    }

    /**
     * 从活跃索引里摘掉僵尸成员。
     *
     * @param array<int,string> $jtis
     * @return int 实际摘掉的条数
     */
    private static function dropOrphans(array $jtis): int
    {
        $removed = 0;

        foreach ($jtis as $jti) {
            try {
                $removed += (int)Redis::zRem(self::INDEX_KEY, $jti);
            } catch (Throwable) {
                // 忽略单条失败
            }
        }

        return $removed;
    }

    /** @param array<string,mixed> $row */
    private static function decorate(string $jti, array $row): array
    {
        $ua     = (string)($row['ua'] ?? '');
        $client = self::parseUa($ua);

        return [
            'jti'       => $jti,
            'user_id'   => (int)($row['user_id'] ?? 0),
            'username'  => (string)($row['username'] ?? ''),
            'nickname'  => (string)($row['nickname'] ?? ''),
            'ip'        => (string)($row['ip'] ?? ''),
            'ua'        => $ua,
            'device'    => $client['device'],
            'os'        => $client['os'],
            'browser'   => $client['browser'],
            'login_at'  => self::date((int)($row['login_at'] ?? 0)),
            'last_at'   => self::date((int)($row['last_at'] ?? 0)),
            // 令牌过期时间（签发时固定，`touch()` 不延长）
            'expire_at' => self::date((int)($row['exp'] ?? 0)),
        ];
    }

    /**
     * 列头筛选匹配：文本字段包含匹配、时间字段落在区间内，字段之间是 AND。
     *
     * 时间比较用的是 Hash 里的原始时间戳字段（`login_at` / `last_at` / `exp`），
     * 不是 `decorate()` 格式化后的字符串。
     *
     * @param array<string,mixed> $row
     * @param array{text:array<string,string>,ranges:array<string,array{0:int,1:int}>} $filters
     */
    private static function match(array $row, array $filters): bool
    {
        foreach ($filters['text'] as $field => $keyword) {
            if (!str_contains((string)($row[$field] ?? ''), $keyword)) {
                return false;
            }
        }

        foreach ($filters['ranges'] as $field => [$from, $to]) {
            $value = (int)($row[$field] ?? 0);
            // 该会话没有这个时间点（如未设置过期时间）：不满足范围条件
            if ($value <= 0) {
                return false;
            }
            if ($from > 0 && $value < $from) {
                return false;
            }
            if ($to > 0 && $value > $to) {
                return false;
            }
        }

        return true;
    }

    /**
     * 从 UA 派生终端信息（设备类型 / 操作系统 / 浏览器）。
     *
     * 只存 UA、展示时再派生：旧会话无需迁移，UA 解析规则调整后历史记录同样生效。
     *
     * @return array{device:string,os:string,browser:string}
     */
    private static function parseUa(string $ua): array
    {
        $s = strtolower($ua);

        $device = '电脑';
        if ($s === '') {
            $device = '未知';
        } elseif (self::containsAny($s, ['bot', 'spider', 'crawler', 'curl/', 'wget/', 'python-requests', 'headless'])) {
            $device = '爬虫';
        } elseif (str_contains($s, 'ipad') || (str_contains($s, 'android') && !str_contains($s, 'mobile'))) {
            $device = '平板';
        } elseif (self::containsAny($s, ['mobile', 'iphone', 'ipod', 'android', 'windows phone'])) {
            $device = '移动端';
        }

        $os = '';
        if (self::containsAny($s, ['iphone', 'ipad', 'ipod'])) {
            $os = 'iOS';
        } elseif (str_contains($s, 'harmony')) {
            $os = 'HarmonyOS';
        } elseif (str_contains($s, 'android')) {
            $os = 'Android';
        } elseif (str_contains($s, 'windows')) {
            $os = 'Windows';
        } elseif (str_contains($s, 'mac os x') || str_contains($s, 'macintosh')) {
            $os = 'macOS';
        } elseif (str_contains($s, 'linux')) {
            $os = 'Linux';
        }

        return ['device' => $device, 'os' => $os, 'browser' => self::detectBrowser($s)];
    }

    /** @param string $s 已转小写的 UA；顺序敏感：微信/Edge 等的 UA 里同时含有 Chrome/Safari */
    private static function detectBrowser(string $s): string
    {
        if (str_contains($s, 'micromessenger')) {
            return '微信';
        }
        if (self::containsAny($s, ['edg/', 'edga', 'edgios'])) {
            return 'Edge';
        }
        if (str_contains($s, 'opr/') || str_contains($s, 'opera')) {
            return 'Opera';
        }
        if (str_contains($s, 'firefox/') || str_contains($s, 'fxios')) {
            return 'Firefox';
        }
        if (str_contains($s, 'chrome/') || str_contains($s, 'crios')) {
            return 'Chrome';
        }
        if (str_contains($s, 'safari/')) {
            return 'Safari';
        }
        if (str_contains($s, 'msie') || str_contains($s, 'trident/')) {
            return 'IE';
        }

        return '其他';
    }

    /** @param string[] $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function date(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
