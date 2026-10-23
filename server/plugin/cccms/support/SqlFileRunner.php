<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * SQL 文件执行器（幂等）。
 *
 * 供「业务插件建表」使用：业务插件的 `plugin/{插件}/db/schema.sql` 用
 * `CREATE TABLE IF NOT EXISTS` 书写，可重复执行。约定：
 *   - 去掉整行注释（`--` / `#`）后按分号切分；
 *   - 只执行 DDL/DML 关键字开头的语句（跳过残余空白/注释片段）。
 */
final class SqlFileRunner
{
    /** 语句首关键字白名单 */
    private const ALLOWED = '/^(SET|CREATE|ALTER|INSERT|REPLACE|UPDATE|DELETE|DROP|TRUNCATE)\b/i';

    /**
     * 执行一个 SQL 文件，返回实际执行的语句数。
     *
     * @param bool $tolerateExisting 容忍「重复列 / 重复索引」：迁移文件用。
     *                               同一份结构在 schema.sql 也有一份，新装环境先建全，
     *                               迁移再跑一次必然撞名 —— 这不是错误，已生效即可。
     * @throws ApiException 文件不存在或某条语句执行失败
     */
    public static function run(string $file, bool $tolerateExisting = false): int
    {
        if (!is_file($file)) {
            throw new ApiException('SQL 文件不存在：' . $file, 500);
        }

        $sql = (string)file_get_contents($file);
        $sql = preg_replace('/^[ \t]*(--|#).*$/m', '', $sql) ?? $sql;

        $count = 0;
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || !preg_match(self::ALLOWED, $statement)) {
                continue;
            }
            try {
                Db::execute($statement);
            } catch (Throwable $e) {
                if ($tolerateExisting && preg_match('/Duplicate (column|key) name/i', $e->getMessage()) === 1) {
                    $count++;
                    continue; // 结构已存在（schema.sql 已建），按已生效处理
                }
                /*
                 * 报错必须带上「哪个文件、什么时候改的、第几条语句」。
                 * 线上踩过：代码改了但服务器上还是旧文件，报错只给一段 SQL，
                 * 完全看不出执行的是哪份 schema，白排查半天（数据导入 ≠ 代码部署）。
                 */
                throw new ApiException('SQL 执行失败：' . $e->getMessage()
                    . '（语句：' . mb_substr($statement, 0, 120) . '）'
                    . '（文件：' . $file . '；修改时间：' . date('Y-m-d H:i:s', (int)@filemtime($file))
                    . '；第 ' . ($count + 1) . ' 条语句）', 500);
            }
            $count++;
        }

        return $count;
    }

    /**
     * 业务插件的 schema 文件（排除 cccms 自身：它的 db/schema.sql 由 db-upgrade 直接执行）。
     *
     * @return array<int,string>
     */
    public static function pluginSchemaFiles(): array
    {
        $files = [];
        foreach (glob(base_path() . '/plugin/*/db/schema.sql') ?: [] as $file) {
            if (str_contains(str_replace('\\', '/', $file), '/plugin/cccms/')) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }

    /**
     * 业务插件的 seed 文件（排除 cccms 自身：它的种子数据由安装流程负责）。
     *
     * 与 schema.sql 走同一执行器（幂等约定一致：INSERT IGNORE /
     * INSERT ... SELECT ... WHERE NOT EXISTS），由 db-upgrade 在 schema 之后执行，
     * 供插件登记定时任务、配置项、数据权限受控表等种子数据。
     *
     * @return array<int,string>
     */
    public static function pluginSeedFiles(): array
    {
        $files = [];
        foreach (glob(base_path() . '/plugin/*/db/seed.sql') ?: [] as $file) {
            if (str_contains(str_replace('\\', '/', $file), '/plugin/cccms/')) {
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }

    /** 由 schema / seed 文件路径推断插件名 */
    public static function pluginNameOf(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        if (preg_match('#/plugin/([^/]+)/db/(schema|seed)\.sql$#', $normalized, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }
}
