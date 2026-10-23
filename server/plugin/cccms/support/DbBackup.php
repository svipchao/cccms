<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\support\DbBackup\Dumper;
use plugin\cccms\support\DbBackup\SqlDumpReader;
use think\facade\Db;
use Throwable;

/**
 * 数据库备份 / 还原（F3）。
 *
 * ## 两种导出引擎
 *
 * - `mysqldump`：优先。用 `proc_open` 的**数组形式**参数（不经 shell，路径/密码里的特殊字符
 *   不会变成命令注入），密码走 `MYSQL_PWD` 环境变量而不是命令行参数（避免 `ps` 里看到）。
 *   `--single-transaction --quick`：InnoDB 下拿一致性快照，不锁表。
 * - `pdo`：降级。没装 mysqldump（Windows 开发机常见 / 精简镜像）时逐表 `SHOW CREATE TABLE`
 *   + 分批 `SELECT` 拼 INSERT。**不做一致性快照**（没有可用的跨表事务读），因此这类备份
 *   在写入高峰可能自相矛盾 —— 会记在 `engine` 字段里，让人知道手里这份是什么。
 *
 * ## 文件格式
 *
 * 统一是 `.sql.gz`，未压缩正文的骨架与 mysqldump 一致（`-- Table structure for table \`x\``），
 * 这样「按表还原」只有一条解析路径；两种引擎产出的文件可以互相还原。
 *
 * ## 为什么按表还原
 *
 * 全量还原会把当前库整个覆盖掉，是**不可逆**操作。支持只还原某几张表，才能做
 * 「误删一张表 → 从昨天的备份里捞回来」这种日常救火，而不必赌上整库。
 *
 * ## 能力边界（必须知道）
 *
 * - 视图 / 存储过程 / 触发器 / 事件**不备份**：它们要么需要 `DELIMITER`，要么依赖
 *   DEFINER 账号，恢复时容易因为账号不存在而失败；DDL 也不在事务里，失败无法整体回滚。
 * - MySQL 的 DDL 隐式提交，还原中途失败会留下「部分表已换掉」的状态 —— 所以调用方
 *   **必须先做一份 pre_restore 备份**（见 `BackupLogic::restore`）。
 */
final class DbBackup
{
    public const ENGINE_MYSQLDUMP = 'mysqldump';
    public const ENGINE_PDO       = 'pdo';

    /** 归档目录（相对 runtime；**不进 public**） */
    public const DIR = 'backups';

    /** 备份目录的绝对路径（不存在则创建） */
    public static function dir(): string
    {
        $dir = runtime_path() . DIRECTORY_SEPARATOR . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException('备份目录无法创建：' . $dir, 500);
        }

        return $dir;
    }

    /** 归档文件的绝对路径（文件名的basename 校验由调用方负责） */
    public static function path(string $relative): string
    {
        return runtime_path() . DIRECTORY_SEPARATOR . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /** 当前连接信息（mysqldump 用） */
    public static function connection(): array
    {
        $cfg = Db::connect()->getConfig();

        return [
            'host'     => (string)($cfg['hostname'] ?? '127.0.0.1'),
            'port'     => (string)($cfg['hostport'] ?? '3306'),
            'database' => (string)($cfg['database'] ?? ''),
            'username' => (string)($cfg['username'] ?? ''),
            'password' => (string)($cfg['password'] ?? ''),
        ];
    }

    /** mysqldump 是否可用（探测与缓存在 `Dumper`；装了之后重启一次进程即可识别） */
    public static function mysqldumpAvailable(): bool
    {
        return Dumper::available();
    }

    /**
     * 生成一份备份，返回落库所需的元信息。
     *
     * @return array{file_name:string,file_path:string,size:int,checksum:string,engine:string,table_count:int,row_count:int,duration:int}
     */
    public static function create(string $kind): array
    {
        $conn = self::connection();
        if ($conn['database'] === '') {
            throw new ApiException('无法确定当前数据库名（database 配置为空）', 500);
        }

        $name  = date('YmdHis') . '-' . preg_replace('/[^a-z_]/', '', $kind) . '-' . bin2hex(random_bytes(3)) . '.sql.gz';
        $gz    = self::dir() . DIRECTORY_SEPARATOR . $name;
        $plain = $gz . '.tmp';
        $start = microtime(true);

        $engine = self::ENGINE_PDO;
        $rows   = 0;
        $tables = 0;

        try {
            // 引擎选择与降级都在 Dumper 内部（mysqldump 失败 → 自动退回 PDO）
            $dump   = Dumper::dump($plain, $conn);
            $engine = $dump['engine'];
            $tables = $dump['tables'];
            $rows   = $dump['rows'];

            Dumper::compress($plain, $gz);
        } catch (Throwable $e) {
            @unlink($gz);
            throw $e;
        } finally {
            @unlink($plain);
        }

        clearstatcache(true, $gz);

        return [
            'file_name'   => $name,
            'file_path'   => self::DIR . '/' . $name,
            'size'        => (int)@filesize($gz),
            'checksum'    => (string)@hash_file('sha256', $gz),
            'engine'      => $engine,
            'table_count' => $tables,
            'row_count'   => $rows,
            'duration'    => (int)round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * 归档里包含哪些表（给「按表还原」的选择框用）。
     *
     * 流式读取，不解压整个文件。
     *
     * @return list<string>
     */
    public static function tablesIn(string $file): array
    {
        return SqlDumpReader::tables($file);
    }

    /**
     * 还原归档。
     *
     * @param  list<string>|null $tables 只还原这些表；null = 全量
     * @return array{tables:int,statements:int}
     */
    public static function restore(string $file, ?array $tables = null): array
    {
        if (!is_file($file)) {
            throw new ApiException('备份文件不存在或已被清理', 404);
        }

        $wanted = $tables === null ? null : array_fill_keys(array_map('strval', $tables), true);
        if ($wanted !== null && $wanted === []) {
            throw new ApiException('请至少选择一张要还原的表', 422);
        }

        $restored   = 0;
        $statements = 0;
        $current    = null;
        $buffer     = '';
        $pending    = '';

        // 关外键检查：否则「先删父表再建」的顺序问题会让还原半途而废。
        // 放在 try/finally 里，失败也必须恢复，不然连接（长驻）后续写入全受影响。
        Db::execute('SET FOREIGN_KEY_CHECKS=0');

        try {
            SqlDumpReader::eachLine($file, static function (string $line) use (
                &$current, &$pending, &$restored, &$statements, $wanted
            ): void {
                $marker = SqlDumpReader::tableOfMarker($line);
                if ($marker !== null) {
                    $current = $marker;
                    if ($wanted === null || isset($wanted[$marker])) {
                        $restored++;
                    }

                    return;
                }

                $statement = rtrim($line);
                if ($statement === '' || str_starts_with($statement, '--') || str_starts_with($statement, '#')) {
                    return;
                }

                // 表外语句（文件头的 SET NAMES 等）只属于全量还原
                $apply = $wanted === null || ($current !== null && isset($wanted[$current]));
                if (!$apply) {
                    return;
                }

                $pending .= ($pending === '' ? '' : "\n") . $statement;
                if (!str_ends_with($statement, ';')) {
                    return;
                }

                // 单条一条执行：出错时能明确是哪条、哪张表，而不是「还原失败」四个字
                try {
                    Db::execute($pending);
                } catch (Throwable $e) {
                    throw new ApiException(
                        '还原失败（表 ' . ($current ?? '—') . '）：' . $e->getMessage()
                        . '（语句：' . mb_substr($pending, 0, 120) . '）',
                        500
                    );
                }

                $pending = '';
                $statements++;
            });
        } finally {
            // 恢复失败时连接可能已断，这条语句再抛异常会把真正的错误盖掉
            try {
                Db::execute('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
                // 连接层面的问题：交给 think-orm 的 break_reconnect 处理
            }
        }

        if ($statements === 0) {
            throw new ApiException('归档里没有可还原的语句（文件可能损坏）', 422);
        }

        return ['tables' => $restored, 'statements' => $statements];
    }

    /** 删除归档文件（不存在视为成功） */
    public static function delete(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }

}
