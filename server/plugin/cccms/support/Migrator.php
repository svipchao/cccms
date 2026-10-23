<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * 结构迁移执行器。
 *
 * 为什么需要：`db/schema.sql` 全是 `CREATE TABLE IF NOT EXISTS` —— 它能建**缺失的表**，
 * 但对已存在的表不做任何 `ALTER`。没有迁移机制时，新增列 / 索引 / 默认值调整只能靠人记得
 * 在每台库上手工执行，且没有任何地方记录「这台库已经到哪一版」。
 *
 * 约定：
 *   - 迁移文件放在 `plugin/{插件}/db/migrations/*.sql`，文件名即版本号，
 *     格式 `YYYYMMDD_HHMMSS_描述.sql`（如 `20261023_120000_data_rule_index.sql`；
 *     字典序 = 执行序，跨插件按版本号全局排序）。
 *   - 已执行的版本记录在 `sys_schema_migration`，按版本号**只执行一次**。
 *   - 文件内容可包含多条语句，沿用 `SqlFileRunner` 的切分规则；
 *     「重复列 / 重复索引」视为已生效（同一份结构在 `schema.sql` 里也有一份，新装环境先建全）。
 *   - 迁移只增不改：已应用的文件被改动时只报「漂移」告警，不会重跑（重跑等于随机改生产结构）。
 */
final class Migrator
{
    /** 版本记录表 */
    public const TABLE = 'sys_schema_migration';

    /** 合法版本号：YYYYMMDD_HHMMSS_小写描述 */
    private const VERSION_PATTERN = '/^\d{8}_\d{6}_[a-z0-9_]+$/';

    /**
     * 建版本表（幂等）。
     *
     * 放在这里而不是 schema.sql：存量库升级时 schema.sql 已经跑过，
     * 新表必须由代码自己补，否则第一次迁移就无处记录。
     */
    public static function ensureTable(): void
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS `' . self::table() . '` ('
            . '`version` varchar(64) NOT NULL COMMENT \'迁移版本（文件名去扩展名）\','
            . '`plugin` varchar(32) NOT NULL DEFAULT \'cccms\' COMMENT \'所属插件\','
            . '`file` varchar(191) NOT NULL DEFAULT \'\' COMMENT \'相对路径\','
            . '`checksum` char(40) NOT NULL DEFAULT \'\' COMMENT \'文件 SHA1（发现被改动）\','
            . '`batch` int NOT NULL DEFAULT 1 COMMENT \'批次（同一轮执行共用）\','
            . '`applied_at` datetime DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`version`),'
            . 'KEY `idx_batch` (`batch`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT=\'结构迁移记录\''
        );
    }

    /**
     * 扫描所有插件的迁移文件，按版本号全局排序。
     *
     * @return array<int,array{version:string,plugin:string,file:string,path:string,checksum:string}>
     */
    public static function discover(?string $basePath = null): array
    {
        $base = rtrim($basePath ?? base_path(), '/\\');
        $files = glob($base . '/plugin/*/db/migrations/*.sql') ?: [];

        $items = [];
        foreach ($files as $path) {
            $version = self::versionOf($path);
            if (!self::isValidVersion($version)) {
                continue; // 命名不合规的文件不执行：排序不可预期比漏跑更危险
            }
            $normalized = str_replace('\\', '/', $path);
            preg_match('#/plugin/([^/]+)/db/migrations/#', $normalized, $m);
            $items[] = [
                'version'  => $version,
                'plugin'   => $m[1] ?? 'unknown',
                'file'     => basename($path),
                'path'     => $path,
                'checksum' => (string)sha1_file($path),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return [$a['version'], $a['plugin']] <=> [$b['version'], $b['plugin']];
        });

        return $items;
    }

    /** 由文件路径取版本号（文件名去掉 `.sql`） */
    public static function versionOf(string $path): string
    {
        return preg_replace('/\.sql$/i', '', basename($path)) ?? '';
    }

    /** 版本号是否合法（决定是否执行） */
    public static function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    /**
     * 已应用的版本。
     *
     * @return array<string,array{plugin:string,checksum:string,batch:int,applied_at:string}>
     */
    public static function applied(): array
    {
        $rows = Db::name(str_replace('sys_', '', self::TABLE))
            ->field('version, plugin, checksum, batch, applied_at')
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['version']] = [
                'plugin'     => (string)$row['plugin'],
                'checksum'   => (string)$row['checksum'],
                'batch'      => (int)$row['batch'],
                'applied_at' => (string)($row['applied_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 执行所有未应用的迁移。
     *
     * @param callable(string,string):void|null $log 进度回调（级别, 文案）
     * @return array{applied:array<int,string>,drifted:array<int,string>}
     *         applied = 本次执行的版本；drifted = 已执行但文件被改动过（只告警）
     */
    public static function run(?callable $log = null): array
    {
        self::ensureTable();

        $applied = self::applied();
        $batch   = self::nextBatch($applied);

        $result = ['applied' => [], 'drifted' => []];

        foreach (self::discover() as $item) {
            $version = $item['version'];
            $known   = $applied[$version] ?? null;

            if ($known !== null) {
                // 已执行过：文件被改过只提示，不重跑 —— 重跑等于在生产上随机改结构
                if ($known['checksum'] !== '' && $known['checksum'] !== $item['checksum']) {
                    $result['drifted'][] = $version;
                    self::log($log, 'warn', "  <comment>迁移文件已变更</comment> {$version}（不会重跑，如需变更请新增迁移）");
                }
                continue;
            }

            try {
                SqlFileRunner::run($item['path'], true);
            } catch (Throwable $e) {
                throw new ApiException(
                    "迁移失败 {$item['plugin']}/{$version}：" . $e->getMessage(),
                    500
                );
            }

            Db::name(str_replace('sys_', '', self::TABLE))->insert([
                'version'    => $version,
                'plugin'     => $item['plugin'],
                'file'       => $item['file'],
                'checksum'   => $item['checksum'],
                'batch'      => $batch,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);

            $result['applied'][] = $version;
            self::log($log, 'info', "  <info>迁移</info> {$item['plugin']}/{$version}");
        }

        return $result;
    }

    /** 表名（带当前连接前缀） */
    private static function table(): string
    {
        return Db::name(str_replace('sys_', '', self::TABLE))->getTable();
    }

    /** @param array<string,mixed> $applied */
    private static function nextBatch(array $applied): int
    {
        $max = 0;
        foreach ($applied as $row) {
            $max = max($max, (int)$row['batch']);
        }

        return $max + 1;
    }

    /** @param callable(string,string):void|null $log */
    private static function log(?callable $log, string $level, string $message): void
    {
        if ($log !== null) {
            $log($level, $message);
        }
    }
}
