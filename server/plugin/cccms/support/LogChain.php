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
 * `row_hash = HMAC-SHA256(key, prev_hash + "\n" + 规范化行内容)`；密钥取
 * `AUDIT_SIGNING_KEY`，不在库里 —— 改了行也重算不出后续链。规范化按固定顺序/类型/长度
 * 取值再 json_encode，保证写入与校验字节一致。
 *
 * 字段集固定（含 action_id / session_id）：新增参与哈希的列直接改这里并重建库，
 * 本项目不保留历史行兼容。链尾与校验起点存在单行表 `sys_log_chain` —— 只查最大 id
 * 发现不了「尾行被删」。
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
     * 降级计数键：没能入链的写入次数累计。
     *
     * 「链路不可用 / 未配签名密钥时降级为普通插入」是刻意取舍（日志比链路重要），
     * 但**降级必须能被看见** —— 否则等于静默失去防篡改能力，而没人会去翻日志。
     */
    public const DEGRADED_KEY = 'cccms:logchain:degraded';

    /**
     * 参与哈希的字段集（顺序即哈希顺序）。
     */
    private const FIELDS = [
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

    /**
     * 写入一条日志（**唯一入口**：中间件与登录逻辑都走这里）。
     *
     * @param array<string,mixed> $row 未规范化的行数据（缺字段自动补默认值）
     */
    public static function write(array $row): int
    {
        $data = self::normalize($row);

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

            // 必须先算哈希再挂 `prev_hash`：normalize() 只产出业务字段，
            // 把 prev_hash 混进载荷会让校验侧算不出同一个值（表现为「所有行都被篡改」）
            $rowHash = self::hash($prev, $data);

            $data['prev_hash'] = $prev;
            $data['row_hash']  = $rowHash;

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
     * 规范化字段集（**写入与校验共用**，保证字节一致）。
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function normalize(array $row): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [$type, $limit, $default]) {
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
     * 计算行哈希：HMAC-SHA256(prev_hash + "\n" + 规范化行内容)。
     *
     * @param array<string,mixed> $normalized 已规范化的行
     */
    public static function hash(string $prevHash, array $normalized): string
    {
        $payload = $prevHash . "\n"
            . (string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $payload, self::signingKey());
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

    /** 链表是否就绪（建表后无需重启：负结论只缓存 60 秒） */
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
            self::$ready = true;
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
