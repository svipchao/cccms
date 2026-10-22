<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use RuntimeException;
use support\Log;
use support\Redis;
use think\facade\Db;
use Throwable;

/**
 * 审计日志的链式哈希（防篡改）+ 唯一的 sys_log 写入入口。
 *
 * 每行存 `prev_hash`（上一条的 row_hash）与
 * `row_hash = sha256(prev_hash + "\n" + 规范化行内容)`；规范化按固定顺序/类型/长度
 * 取值再 json_encode，保证写入侧与校验侧字节一致（否则 PDO 把 int 读成字符串就会误报）。
 *
 * 新增参与哈希的列必须升 `hash_version`（0 = 16 字段，1 = 追加 action_id / session_id），
 * 否则历史行会全部被判成篡改。链尾与校验起点存在单行表 `sys_log_chain` —— 只查最大 id
 * 发现不了「尾行被删」，而删尾行正是最常见的掩盖手段。
 *
 * 并发：读链尾 → 插入 → 推进链尾必须在同一事务里并 `SELECT ... FOR UPDATE` 锁链尾，
 * 否则会写出分叉链。链式哈希是加固能力而非前置条件，任何异常都降级为不带哈希的普通
 * 插入（row_hash 为空，校验时标为「未纳入链」）。
 */
final class LogChain
{
    /** 单行链状态表的固定主键 */
    public const CHAIN_ID = 1;

    /** 链表不可用时的重检间隔（秒）：避免每次写日志都白跑一次失败的查询 */
    private const RECHECK_SECONDS = 60;

    /**
     * 当前哈希版本。
     *
     * - `v0`：16 个业务字段，无密钥 `sha256`；
     * - `v1`：在 v0 基础上加入 `action_id` / `session_id`，仍是无密钥 `sha256`；
     * - `v2`：字段集同 v1，**算法改为 HMAC-SHA256**（密钥取 `AUDIT_SIGNING_KEY`）。
     *
     * 为什么要 v2：无密钥的 `sha256` 只能防「改单行不重算」和「删中间行」，
     * 挡不住有库权限的人——他改完一行后可以把后续所有行的 `row_hash` 全部重算，
     * 链校验照样通过。HMAC 的密钥不在库里，这条重算路径才被真正堵死。
     */
    public const VERSION = 2;

    /**
     * 降级计数键：没能入链的写入次数累计。
     *
     * 「链路不可用 / 未配签名密钥时降级为普通插入」是刻意取舍（日志比链路重要），
     * 但**降级必须能被看见** —— 否则等于静默失去防篡改能力，而没人会去翻日志。
     */
    public const DEGRADED_KEY = 'cccms:logchain:degraded';

    /**
     * v0：最早的字段集（历史行 `hash_version = 0`，必须**逐字保持不变**）。
     *
     * `字段 => [类型, 长度, 默认值]`；**顺序即哈希顺序**。
     */
    private const FIELDS_V0 = [
        'user_id'     => ['int', 0, 0],
        'username'    => ['str', 64, ''],
        'method'      => ['str', 16, ''],
        'path'        => ['str', 255, ''],
        'node'        => ['str', 128, ''],
        'title'       => ['str', 128, ''],
        'status'      => ['int', 0, 1],
        'message'     => ['str', 255, ''],
        'trace_id'    => ['str', 32, ''],
        'ip'          => ['str', 64, ''],
        'ua'          => ['str', 255, ''],
        'params'      => ['str', 8000, ''],
        'result'      => ['str', 8000, ''],
        'status_code' => ['int', 0, 200],
        'cost'        => ['int', 0, 0],
        'create_time' => ['str', 19, ''],
    ];

    /**
     * v1：加入「操作 ID / 会话 ID」。
     *
     * 为什么不在 v0 上直接加字段：校验侧要用**同一份字段集**重算哈希，直接改 v0
     * 会让所有历史行被误判为「内容被篡改」。版本号写在行上（`hash_version`），
     * 校验时按行取对应字段集，新旧行都能通过。
     *
     * ⚠️ v1 一旦发布就不可再改：以后新增字段请开 v2，并同步 `db/schema.sql` 的列定义。
     */
    private const FIELDS_V1 = [
        'user_id'     => ['int', 0, 0],
        'username'    => ['str', 64, ''],
        'method'      => ['str', 16, ''],
        'path'        => ['str', 255, ''],
        'node'        => ['str', 128, ''],
        'title'       => ['str', 128, ''],
        'status'      => ['int', 0, 1],
        'message'     => ['str', 255, ''],
        'trace_id'    => ['str', 32, ''],
        'action_id'   => ['str', 32, ''],
        'session_id'  => ['str', 32, ''],
        'ip'          => ['str', 64, ''],
        'ua'          => ['str', 255, ''],
        'params'      => ['str', 8000, ''],
        'result'      => ['str', 8000, ''],
        'status_code' => ['int', 0, 200],
        'cost'        => ['int', 0, 0],
        'create_time' => ['str', 19, ''],
    ];

    private static ?bool $ready = null;

    private static int $checkedAt = 0;

    /** 新增列是否就绪（迁移未执行时降级为不带新列的普通写入，日志比链路更重要） */
    private static bool $columnsReady = true;

    /**
     * 写入一条日志（**唯一入口**：中间件与登录逻辑都走这里）。
     *
     * @param array<string,mixed> $row 未规范化的行数据（缺字段自动补默认值）
     */
    public static function write(array $row): int
    {
        $data = self::normalize($row, self::VERSION);

        // 链不可用、或未配置签名密钥（v2 起必需）时降级为普通插入：
        // 少一条链，总比整条日志丢掉好。降级次数记在 `cccms:logchain:degraded`，由 /metrics 暴露。
        if (!self::ready() || !self::hasSigningKey()) {
            return self::plainInsert($data);
        }

        try {
            Db::startTrans();

            // 锁住链尾行：并发写入串行化，避免两条记录从同一个 prev_hash 分叉
            $head = Db::name('log_chain')->where('id', self::CHAIN_ID)->lock(true)->find();
            if (!$head) {
                Db::name('log_chain')->insert([
                    'id'          => self::CHAIN_ID,
                    'tail_id'     => 0,
                    'tail_hash'   => '',
                    'anchor_id'   => 0,
                    'anchor_hash' => '',
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
                $head = ['tail_hash' => '', 'anchor_id' => 0];
            }

            $prev = (string)($head['tail_hash'] ?? '');

            // ⚠️ 必须先算哈希再挂 `prev_hash`：`normalize()` 只产出 16 个业务字段，
            // 若把 `prev_hash` 混进参与哈希的载荷，校验侧（只 normalize 业务字段）
            // 永远算不出同一个值 —— 表现为「所有行都被判为已篡改」。
            $rowHash = self::hash($prev, $data);

            // 版本号 / 链字段都不参与哈希：它们由链自身维护，混进载荷会让校验侧算不出同一个值
            $data['hash_version'] = self::VERSION;
            $data['prev_hash']    = $prev;
            $data['row_hash']     = $rowHash;

            $id = (int)Db::name('log')->insertGetId($data);

            $update = [
                'tail_id'     => $id,
                'tail_hash'   => $rowHash,
                'update_time' => date('Y-m-d H:i:s'),
            ];
            // 首次写入（或历史行全被清空过）时把校验起点一并定在这一行
            if ((int)($head['anchor_id'] ?? 0) <= 0) {
                $update['anchor_id']   = $id;
                $update['anchor_hash'] = $rowHash;
            }
            Db::name('log_chain')->where('id', self::CHAIN_ID)->update($update);

            Db::commit();

            return $id;
        } catch (Throwable $e) {
            try {
                Db::rollback();
            } catch (Throwable) {
                // 忽略
            }
            self::$ready = false;
            self::$checkedAt = time();
            Log::error('log chain write failed, fallback to plain insert: ' . $e->getMessage());

            return self::plainInsert($data);
        }
    }

    /**
     * 重新锚定：应用自己删过日志（管理员删除 / 清空 / 归档 / 定时清理）之后调用。
     *
     * 把校验起点移到**当前第一条带哈希的行**，并同步链尾。
     * 这样"合法删除"不会在后续校验里表现为断点；而链中间的删除**仍会**被报出来
     * （那是无法与篡改区分的情况，交给人工按 `cccms:log-verify` 报出的 id 核对）。
     */
    public static function reanchor(): void
    {
        if (!self::ready()) {
            return;
        }

        try {
            $first = Db::name('log')->where('row_hash', '<>', '')->order('id', 'asc')->find();
            $last  = Db::name('log')->where('row_hash', '<>', '')->order('id', 'desc')->find();

            // 单行状态不存在时先建出来（例如「升级后第一次操作就是清理日志」）
            if (!Db::name('log_chain')->where('id', self::CHAIN_ID)->find()) {
                Db::name('log_chain')->insert([
                    'id'          => self::CHAIN_ID,
                    'tail_id'     => 0,
                    'tail_hash'   => '',
                    'anchor_id'   => 0,
                    'anchor_hash' => '',
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }

            Db::name('log_chain')->where('id', self::CHAIN_ID)->update([
                'anchor_id'   => (int)($first['id'] ?? 0),
                'anchor_hash' => (string)($first['row_hash'] ?? ''),
                'tail_id'     => (int)($last['id'] ?? 0),
                'tail_hash'   => (string)($last['row_hash'] ?? ''),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Log::error('log chain reanchor failed: ' . $e->getMessage());
        }
    }

    /** 链状态（tail / anchor），表不存在时返回 null */
    public static function state(): ?array
    {
        try {
            $row = Db::name('log_chain')->where('id', self::CHAIN_ID)->find();

            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 规范化某一版本的字段集（**写入与校验共用**，保证字节一致）。
     *
     * 校验侧必须按行上的 `hash_version` 传入对应版本：0 = 历史行（16 字段），
     * 1 = 含 action_id / session_id 的新行。传错版本会把整段链判成「被篡改」。
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function normalize(array $row, int $version = self::VERSION): array
    {
        $out = [];
        foreach (self::fields($version) as $field => [$type, $limit, $default]) {
            $value = $row[$field] ?? null;

            if ($type === 'int') {
                $out[$field] = $value === null || $value === '' ? $default : (int)$value;
                continue;
            }

            $value = $value === null ? '' : (string)$value;
            if ($field === 'create_time' && $value === '') {
                $value = date('Y-m-d H:i:s');
            }
            // 按**字符**截断而不是字节：utf8mb4 下 varchar(n) 的 n 就是字符数，
            // 按字节截会让中文记录被库二次截断，导致写入侧与校验侧的字节不一致
            $out[$field] = $limit > 0 ? mb_substr($value, 0, $limit) : $value;
        }

        return $out;
    }

    /**
     * 版本 → 字段集。
     *
     * 未知版本按当前版本处理（例如代码回滚后读到更新的行）：此时校验会报
     * `content_modified`，属于**宁可误报也不放过**的取舍 —— 版本号本身已在链外，
     * 无法用旧算法证明新行。
     */
    private static function fields(int $version): array
    {
        // 字段集只有「v0（16 字段）」与「v1 及以后（含 action_id / session_id）」两档；
        // 不能再写成 `>= self::VERSION`：v2 的行用的是 v1 字段集，写成 >= 2 会让 v1 行错用 v0。
        return $version >= 1 ? self::FIELDS_V1 : self::FIELDS_V0;
    }

    /**
     * 计算行哈希。
     *
     * @param array<string,mixed> $normalized 已规范化的行
     * @param int                 $version    按**行上记录的**版本选算法，不能一律用当前版本
     */
    public static function hash(string $prevHash, array $normalized, int $version = self::VERSION): string
    {
        $payload = $prevHash . "\n"
            . (string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($version >= 2) {
            return hash_hmac('sha256', $payload, self::signingKey());
        }

        return hash('sha256', $payload);
    }

    /** 是否已配置链签名密钥。未配置时 `write()` 降级为普通插入（日志比链路更重要）。 */
    public static function hasSigningKey(): bool
    {
        return self::signingKeyRaw() !== '';
    }

    private static function signingKey(): string
    {
        $key = self::signingKeyRaw();
        if ($key === '') {
            throw new RuntimeException('AUDIT_SIGNING_KEY 未配置，无法计算审计链签名');
        }

        return $key;
    }

    private static function signingKeyRaw(): string
    {
        return trim((string)(getenv('AUDIT_SIGNING_KEY') ?: ''));
    }

    /** 链表 / 列是否就绪（升级后无需重启：负结论只缓存 60 秒） */
    private static function ready(): bool
    {
        if (self::$ready === true) {
            return true;
        }
        if (self::$ready === false && time() - self::$checkedAt < self::RECHECK_SECONDS) {
            return false;
        }

        self::$checkedAt = time();

        try {
            Db::name('log_chain')->where('id', self::CHAIN_ID)->count();

            // 新列是否已迁移：缺列时插入会整体失败。此时退回「不带新列」的普通写入，
            // 宁可少 action_id / session_id，也不能把审计日志本身弄丢。
            self::$columnsReady = (bool)Db::query(
                'SELECT 1 FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                ['sys_log', 'hash_version']
            );

            self::$ready = self::$columnsReady;
        } catch (Throwable) {
            self::$ready = false;
        }

        return self::$ready;
    }

    /**
     * 降级写入：不带哈希的普通插入。
     *
     * 刻意把 `prev_hash` / `row_hash` 写成空串而不是省略 —— 省略会落到列默认值，
     * 哪种结果都一样，但显式写空串让"这行没进链"在数据层面一眼可见。
     *
     * @param array<string,mixed> $data
     */
    private static function plainInsert(array $data): int
    {
        self::bumpDegraded();

        // 迁移未执行时不写新列（否则插入会因未知列直接失败，日志就没了）
        if (!self::$columnsReady) {
            unset($data['action_id'], $data['session_id'], $data['hash_version']);
        }

        $data['prev_hash'] = '';
        $data['row_hash']  = '';

        return (int)Db::name('log')->insertGetId($data);
    }

    /**
     * 递增降级计数。
     *
     * 计数落在 Redis 而不是进程内静态变量：Webman 多进程 + 多实例，进程内计数会失真。
     * 计数本身失败（Redis 也挂了）不能影响写日志，因此吞掉异常。
     */
    private static function bumpDegraded(): void
    {
        try {
            Redis::incr(self::DEGRADED_KEY);
        } catch (Throwable) {
            // 计数失败不影响日志写入
        }
    }
}
