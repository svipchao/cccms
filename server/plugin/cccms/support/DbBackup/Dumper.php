<?php

declare(strict_types=1);

namespace plugin\cccms\support\DbBackup;

use plugin\cccms\support\DbBackup;
use RuntimeException;
use support\Log;
use think\facade\Db;
use Throwable;

/**
 * 把当前库导出成未压缩的 `.sql` 骨架（两种引擎），再把文件压成 `.sql.gz`。
 *
 * 与 `DbBackup` 的分工：那边负责「什么时候备份 / 备份放哪 / 还原」，这边只管
 * 「怎么把库倒出来」。两个引擎的行为差异（一致性快照、行数统计）都在这里，
 * 还原侧只需要认文件格式，不关心是哪个引擎写的。
 *
 * - `mysqldump`：优先，`proc_open` 数组参数（不经 shell）+ 密码走 `MYSQL_PWD`。
 * - `pdo`：降级路径，逐表 `SHOW CREATE TABLE` + 分批 `SELECT` 拼 INSERT。
 *   没有跨表一致性快照，写高峰时可能自相矛盾 —— 所以引擎名会写进文件头与备份元信息。
 */
final class Dumper
{
    /** 逐表导出的批量大小（兼顾内存与 INSERT 语句长度） */
    private const BATCH = 200;

    /** mysqldump 探测结果（同进程内二进制不会悄悄消失，缓存即可） */
    private static ?bool $mysqldumpAvailable = null;

    /**
     * 导出到未压缩文件，返回实际使用的引擎与规模。
     *
     * mysqldump 失败（版本不识别参数 / 权限不足 / 半路被杀）时自动降级 PDO：
     * 不能让「有可用的降级路径」变成「备份直接失败」。
     *
     * @param  array{host:string,port:string,database:string,username:string,password:string} $conn
     * @return array{engine:string,tables:int,rows:int}
     */
    public static function dump(string $plain, array $conn): array
    {
        if (self::available()) {
            try {
                return [
                    'engine' => DbBackup::ENGINE_MYSQLDUMP,
                    'tables' => self::viaMysqldump($plain, $conn),
                    'rows'   => 0,
                ];
            } catch (Throwable $e) {
                Log::warning('backup: mysqldump failed, fallback to pdo: ' . $e->getMessage());
                @unlink($plain);
            }
        }

        [$rows, $tables] = self::viaPdo($plain, (string)$conn['database']);

        return ['engine' => DbBackup::ENGINE_PDO, 'tables' => $tables, 'rows' => $rows];
    }

    /** mysqldump 是否可用（结果缓存；装了之后重启一次进程即可识别） */
    public static function available(): bool
    {
        if (self::$mysqldumpAvailable !== null) {
            return self::$mysqldumpAvailable;
        }

        try {
            [$code] = self::exec([self::binary(), '--version']);

            return self::$mysqldumpAvailable = $code === 0;
        } catch (Throwable) {
            return self::$mysqldumpAvailable = false;
        }
    }

    /**
     * 未压缩文件 → gzip（分块，避免大库把内存打满）。
     */
    public static function compress(string $plain, string $gz): void
    {
        $in = fopen($plain, 'rb');
        if ($in === false) {
            throw new RuntimeException('无法读取临时备份文件：' . $plain);
        }
        $out = gzopen($gz, 'wb9');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('无法写入备份归档：' . $gz);
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    gzwrite($out, $chunk);
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    /**
     * mysqldump 导出到未压缩文件，返回表数。
     *
     * @param array<string,string> $conn
     */
    private static function viaMysqldump(string $plain, array $conn): int
    {
        $cmd = [
            self::binary(),
            '--host=' . $conn['host'],
            '--port=' . $conn['port'],
            '--user=' . $conn['username'],
            // 一致性快照 + 不锁表 + 不缓冲整个结果集（大表不至于把内存吃穿）
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            // 视图/触发器/存储过程不备份（见 DbBackup 类注释），显式关掉以免产出需要 DELIMITER 的 SQL
            '--skip-triggers',
            '--result-file=' . $plain,
            $conn['database'],
        ];

        // 密码走环境变量：不进命令行，`ps` 看不到；空密码时不设置
        if ($conn['password'] !== '') {
            putenv('MYSQL_PWD=' . $conn['password']);
        }

        try {
            [$code, , $err] = self::exec($cmd);
        } finally {
            if ($conn['password'] !== '') {
                putenv('MYSQL_PWD');
            }
        }

        if ($code !== 0) {
            throw new RuntimeException('mysqldump 退出码 ' . $code . '：' . trim($err));
        }

        return count(SqlDumpReader::tablesInPlain($plain));
    }

    /**
     * 逐表导出（降级路径）：SHOW CREATE TABLE + 分批 SELECT 拼 INSERT。
     *
     * @return array{0:int,1:int} [行数, 表数]
     */
    private static function viaPdo(string $plain, string $database): array
    {
        $fh = fopen($plain, 'wb');
        if ($fh === false) {
            throw new RuntimeException('无法写入临时备份文件：' . $plain);
        }

        $rows   = 0;
        $tables = 0;

        try {
            fwrite($fh, "-- CCCMS database backup\n");
            fwrite($fh, '-- database: ' . $database . "\n");
            fwrite($fh, '-- engine: ' . DbBackup::ENGINE_PDO . "\n");
            fwrite($fh, '-- created_at: ' . date('Y-m-d H:i:s') . "\n\n");
            fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach (self::baseTables() as $table) {
                $create = Db::query('SHOW CREATE TABLE `' . $table . '`');
                $ddl    = (string)($create[0]['Create Table'] ?? '');
                if ($ddl === '') {
                    continue;
                }

                fwrite($fh, '-- Table structure for table `' . $table . "`\n");
                fwrite($fh, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
                fwrite($fh, $ddl . ";\n\n");
                fwrite($fh, '-- Dumping data for table `' . $table . "`\n");
                $tables++;

                $offset = 0;
                while (true) {
                    $batch = Db::query(sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', $table, self::BATCH, $offset));
                    if ($batch === []) {
                        break;
                    }

                    $offset += count($batch);
                    $rows   += count($batch);

                    $columns = '`' . implode('`,`', array_keys($batch[0])) . '`';
                    $values  = [];
                    foreach ($batch as $row) {
                        $values[] = '(' . implode(',', array_map(
                            static fn (mixed $value): string => self::quote($value),
                            array_values($row)
                        )) . ')';
                    }

                    fwrite($fh, 'INSERT INTO `' . $table . '` (' . $columns . ') VALUES ' . implode(",\n", $values) . ";\n");
                }

                fwrite($fh, "\n");
            }

            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }

        return [$rows, $tables];
    }

    /**
     * 当前库的全部基表（不含视图 —— 它们不备份）。
     *
     * `SHOW FULL TABLES` 的两列名随库名变化（`Tables_in_xxx` / `Table_type`），
     * 所以按位置取值，不按列名。
     *
     * @return list<string>
     */
    private static function baseTables(): array
    {
        $tables = [];
        foreach (Db::query('SHOW FULL TABLES') as $row) {
            $values = array_values($row);
            if (count($values) < 2 || (string)$values[1] !== 'BASE TABLE') {
                continue;
            }
            $tables[] = (string)$values[0];
        }

        return $tables;
    }

    /**
     * SQL 值转义（PDO::quote 在部分驱动/连接上对二进制与换行处理不一致，这里自己来）。
     *
     * 换行必须转成 `\n` 字面量：还原时按行切分语句，正文里出现真实换行会把语句切坏。
     */
    private static function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $escaped = strtr((string)$value, [
            '\\'   => '\\\\',
            "\0"   => '\\0',
            "\n"   => '\\n',
            "\r"   => '\\r',
            "'"    => "\\'",
            '"'    => '\\"',
            "\x1a" => '\\Z',
        ]);

        return "'" . $escaped . "'";
    }

    private static function binary(): string
    {
        return (string)(getenv('MYSQLDUMP_BIN') ?: 'mysqldump');
    }

    /**
     * 执行外部命令（数组形式参数，不经 shell）。
     *
     * @param  array<int,string> $cmd
     * @return array{0:int,1:string,2:string} [退出码, stdout, stderr]
     */
    private static function exec(array $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('无法执行命令：' . implode(' ', $cmd));
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
