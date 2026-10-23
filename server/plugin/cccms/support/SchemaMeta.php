<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * 库表字段元数据（接口文档的枚举值来源）。
 *
 * ## 为什么是库表
 *
 * 项目里没有统一的枚举类：值域散落在 `db/schema.sql` 的 `CHECK (col IN (...))`、
 * 列 `COMMENT`（`'1目录 2菜单 3按钮'`）以及各处常量里。手工维护一份「枚举清单」
 * 必然与库漂移，因此这里直接读 `information_schema`：
 *   - 值集合：`CHECK_CONSTRAINTS.CHECK_CLAUSE`（`in (...)` / `between a and b`）；
 *   - 标签：`COLUMNS.COLUMN_COMMENT` 里紧跟在值后面的说明；
 *   - 字符串枚举：`COLUMN_TYPE = enum('a','b')` 时直接取值。
 *
 * 读不到（无库 / 权限不足 / 老版本 MySQL 无 CHECK_CONSTRAINTS）时**返回空数组**，
 * 文档退化为「只有参数名与类型」，不报错 —— 接口文档不是关键路径，不能因它挂掉。
 *
 * ## 为什么按表区分
 *
 * 同一个列名在不同表里值域不同：`sys_menu.type` 是 1/2/3，`sys_notice.type` 只是 1/2；
 * `sys_job.status` 有 0-4，多数表只有 0/1。所以枚举必须**表 + 列**定位；
 * 拿不到表时只能用 `sharedEnum()`（所有表口径一致的列才给），宁可少写也不写错。
 *
 * 进程内缓存（TTL 60s）：同一请求里 `operations()` 会遍历上百条路由，
 * 每条都查一次元数据表不可接受；60s 也保证改完注释很快生效。
 */
final class SchemaMeta
{
    /** 元数据缓存秒数 */
    private const TTL = 60;

    /** 区间型 CHECK 展开成枚举的上限（`between 1 and 5` → 1..5，太大就不展开） */
    private const MAX_RANGE = 12;

    /**
     * 文档中不列出的列：口令 / 密钥类，列出来等于告诉攻击者去撞哪个字段。
     *
     * 只影响文档展示，不改变接口本身（真正的保护在模型 `$hidden` 与字段规则）。
     */
    private const SENSITIVE = [
        'password', 'salt', 'token', 'secret', 'access_key', 'secret_key',
        'private_key', 'api_key', 'refresh_token',
    ];

    /** @var array<string,array<string,array{type:string,comment:string,enum:array<int,array{value:mixed,label:string}>}>>|null */
    private static ?array $cache = null;

    private static int $cachedAt = 0;

    /**
     * 全库字段元数据：`表名 => 列名 => 元数据`。
     *
     * @return array<string,array<string,array{type:string,comment:string,enum:array<int,array{value:mixed,label:string}>}>>
     */
    public static function tables(): array
    {
        $now = time();
        if (self::$cache !== null && $now - self::$cachedAt < self::TTL) {
            return self::$cache;
        }

        self::$cachedAt = $now;

        try {
            $columns = Db::query(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ct, COLUMN_COMMENT AS cm'
                . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            );
            $checks = Db::query(
                'SELECT tc.TABLE_NAME AS t, cc.CHECK_CLAUSE AS ch'
                . ' FROM information_schema.CHECK_CONSTRAINTS cc'
                . ' INNER JOIN information_schema.TABLE_CONSTRAINTS tc'
                . '   ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA'
                . '  AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME'
                . ' WHERE cc.CONSTRAINT_SCHEMA = DATABASE()'
            );
        } catch (Throwable) {
            // 无库 / 权限不足：降级为空（文档只显示参数名与类型）
            return self::$cache = [];
        }

        // ① CHECK 约束 → 值集合
        $ranges = [];
        foreach ($checks as $row) {
            $parsed = self::parseCheck((string)($row['ch'] ?? ''));
            if ($parsed !== null) {
                $ranges[(string)($row['t'] ?? '')][$parsed['column']] = $parsed['values'];
            }
        }

        // ② 列元数据 + 标签
        $tables = [];
        foreach ($columns as $row) {
            $table = (string)($row['t'] ?? '');
            $column = (string)($row['c'] ?? '');
            if ($table === '' || $column === '') {
                continue;
            }
            if (in_array($column, self::SENSITIVE, true)) {
                continue;
            }

            $columnType = (string)($row['ct'] ?? '');
            $comment = (string)($row['cm'] ?? '');
            $values = $ranges[$table][$column] ?? self::enumValuesOf($columnType);

            $tables[$table][$column] = [
                'type'    => self::typeOf($columnType),
                'comment' => $comment,
                'enum'    => self::withLabels($values, $comment),
            ];
        }

        return self::$cache = $tables;
    }

    /** 单表字段；表不存在返回空数组 */
    public static function table(string $table): array
    {
        return self::tables()[$table] ?? [];
    }

    /** 表是否存在 */
    public static function hasTable(string $table): bool
    {
        return isset(self::tables()[$table]);
    }

    /**
     * 按名字定位真实表名（模型 `$name = 'crontab'` → `sys_crontab`）。
     *
     * 解析顺序：完全同名 → 连接配置前缀 + 名字（`sys_log`）→ 唯一「以 _名字 结尾」。
     * 中间那一步很关键：`log` 既是 `sys_log` 又是 `sys_crontab_log` 的结尾，
     * 不做前缀优先就会判成歧义，等于白白丢掉审计日志的文档。
     */
    public static function resolve(string $name): ?string
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return null;
        }

        $tables = self::tables();
        if (isset($tables[$name])) {
            return $name;
        }

        $prefix = self::prefix();
        if ($prefix !== '' && isset($tables[$prefix . $name])) {
            return $prefix . $name;
        }

        $hit = null;
        foreach ($tables as $table => $_) {
            if (str_ends_with($table, '_' . $name)) {
                // 多个表同结尾（少见）说明无法确定，宁可不猜
                if ($hit !== null) {
                    return null;
                }
                $hit = $table;
            }
        }

        return $hit;
    }

    /**
     * 跨表口径一致的列枚举（请求参数拿不到所属表时的兜底）。
     *
     * `status` / `type` 这类列各表值域不同 → 返回空数组，不给出会误导的候选值。
     *
     * @return array<int,array{value:mixed,label:string}>
     */
    public static function sharedEnum(string $column): array
    {
        $found = null;
        foreach (self::tables() as $columns) {
            if (!isset($columns[$column])) {
                continue;
            }
            $values = array_column($columns[$column]['enum'], 'value');
            if ($found === null) {
                $found = $values;
                continue;
            }
            if ($values !== $found) {
                return [];
            }
        }

        if ($found === null || $found === []) {
            return [];
        }

        // 标签取任意一张表的即可（值域一致）
        foreach (self::tables() as $columns) {
            if (isset($columns[$column]) && $columns[$column]['enum'] !== []) {
                return $columns[$column]['enum'];
            }
        }

        return [];
    }

    /** 表前缀（来自连接配置，默认 `sys_`）；取不到时返回空串，退回「唯一结尾」匹配 */
    private static function prefix(): string
    {
        try {
            $prefix = Db::connect()->getConfig('prefix');
        } catch (Throwable) {
            return '';
        }

        return is_string($prefix) ? $prefix : '';
    }

    /** 清空缓存（测试用） */
    public static function clear(): void
    {
        self::$cache     = null;
        self::$cachedAt = 0;
    }

    /**
     * 解析 CHECK 子句。
     *
     * @return array{column:string,values:array<int,mixed>}|null
     */
    private static function parseCheck(string $clause): ?array
    {
        if (preg_match('/`?(\w+)`?\s+in\s*\((.*)\)/i', $clause, $m) === 1) {
            $values = self::splitValues($m[2]);

            return $values === [] ? null : ['column' => $m[1], 'values' => $values];
        }

        if (preg_match('/`?(\w+)`?\s+between\s+(-?\d+)\s+and\s+(-?\d+)/i', $clause, $m) === 1) {
            $from = (int)$m[2];
            $to   = (int)$m[3];
            if ($to >= $from && $to - $from <= self::MAX_RANGE) {
                return ['column' => $m[1], 'values' => range($from, $to)];
            }
        }

        return null;
    }

    /**
     * 从 `(0, 1)` / `('or', 'and')` 这类列表里取值。
     *
     * MySQL 的 `CHECK_CLAUSE` 会把字符串字面量写成 `_utf8mb4\'cron\'`：
     * 先去转义，再取值；数字两侧加边界，避免把 `_utf8mb4` 里的 8 / 4 当成枚举值。
     *
     * @return array<int,mixed>
     */
    private static function splitValues(string $list): array
    {
        $list = str_replace("\\'", "'", $list);

        preg_match_all(
            "/'((?:[^']|'')*)'|(?<![A-Za-z0-9_])(-?\d+(?:\.\d+)?)(?![A-Za-z0-9_])/",
            $list,
            $matches,
            PREG_SET_ORDER
        );

        $values = [];
        foreach ($matches as $match) {
            $values[] = ($match[1] ?? '') !== '' ? str_replace("''", "'", $match[1]) : self::num($match[2] ?? '');
        }

        return $values;
    }

    /** mysql `enum('a','b')` 的值集合 */
    private static function enumValuesOf(string $columnType): array
    {
        if (preg_match('/^enum\((.*)\)$/i', $columnType, $m) !== 1) {
            return [];
        }

        return self::splitValues($m[1]);
    }

    /** mysql 列类型 → OpenAPI 类型 */
    private static function typeOf(string $columnType): string
    {
        $type = strtolower($columnType);

        return match (true) {
            str_starts_with($type, 'tinyint'),
            str_starts_with($type, 'smallint'),
            str_starts_with($type, 'mediumint'),
            str_starts_with($type, 'int'),
            str_starts_with($type, 'bigint') => 'integer',
            str_starts_with($type, 'decimal'),
            str_starts_with($type, 'float'),
            str_starts_with($type, 'double') => 'number',
            str_starts_with($type, 'bool') => 'boolean',
            default => 'string',
        };
    }

    /**
     * 给值补上列注释里的标签。
     *
     * 注释写法是「值 + 说明」紧挨着（`'1目录 2菜单 3按钮'`、`'是否缓存 1是 0否'`），
     * 逐值去注释里找「值后紧跟的说明」，找不到就留空 —— 不猜。
     *
     * @param  array<int,mixed> $values
     * @return array<int,array{value:mixed,label:string}>
     */
    private static function withLabels(array $values, string $comment): array
    {
        $list = [];
        foreach ($values as $value) {
            $label = '';
            if ($comment !== '') {
                $needle = preg_quote((string)$value, '/');
                // 边界用显式 ASCII 字符类：`/u` 会让 \w 变成 Unicode 语义，
                // `(?<![\w.])1` 会因为「1目录」里的「目」也属于 \w 而永远不命中。
                // 说明部分最多 12 个字符且不含分隔符，避免把下一段值域一起吞进来。
                $pattern = '/(?<![A-Za-z0-9_.])' . $needle
                    . '(?![A-Za-z0-9_.])\s*([^\s\d,，;；、\/|]{1,12})/u';
                if (preg_match($pattern, $comment, $m) === 1) {
                    $label = trim($m[1]);
                }
            }
            $list[] = ['value' => $value, 'label' => $label];
        }

        return $list;
    }

    /** 数字字符串转 int / float（枚举值保持与库一致的数值类型，便于前端比较） */
    private static function num(string $raw): mixed
    {
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int)$raw;
        }

        return (float)$raw;
    }
}
