<?php

declare(strict_types=1);

namespace plugin\cccms\support\DbBackup;

use plugin\cccms\support\ApiException;

/**
 * `.sql.gz` 归档的**流式**读取：逐行回调、表清单、分节标记。
 *
 * 单独拆出来是因为「解析归档」与「生成归档」是两件互相独立的事：
 * 前者只依赖 gzip 与固定行格式，后者依赖 PDO / mysqldump。混在一个类里时，
 * 想给解析逻辑补用例就得连带准备数据库连接 —— 现在不必。
 *
 * 行格式与 mysqldump / PDO 两种引擎共用的骨架一致（`-- Table structure for table \`x\``），
 * 所以「按表还原」只有一条解析路径（见 `DbBackup` 类注释）。
 */
final class SqlDumpReader
{
    /** 每次从 gzip 里读多少字节（单个 INSERT 可能很长，不能一次 gzgets） */
    private const READ_CHUNK = 1048576;

    /** 表结构分节的标记行 */
    private const MARKER = '/^-- Table structure for table `([^`]+)`/';

    /**
     * 流式解压逐行读。
     *
     * 不用 `gzgets()`：它默认只读 1024 字节，长 INSERT 会被截断，还原时表现为
     * 「语句莫名其妙少了一截」。
     *
     * @param callable(string):void $onLine
     */
    public static function eachLine(string $file, callable $onLine): void
    {
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new ApiException('备份文件无法读取（可能是 gzip 损坏）', 422);
        }

        $buffer = '';
        try {
            while (!gzeof($gz)) {
                $chunk = gzread($gz, self::READ_CHUNK);
                if ($chunk === false) {
                    break;
                }
                $buffer .= $chunk;

                $offset = 0;
                while (($pos = strpos($buffer, "\n", $offset)) !== false) {
                    $onLine(substr($buffer, $offset, $pos - $offset));
                    $offset = $pos + 1;
                }
                $buffer = substr($buffer, $offset);
            }

            if ($buffer !== '') {
                $onLine($buffer);
            }
        } finally {
            gzclose($gz);
        }
    }

    /**
     * 归档里出现过的表名（按出现顺序去重）。
     *
     * @return list<string>
     */
    public static function tables(string $file): array
    {
        $tables = [];
        self::eachLine($file, static function (string $line) use (&$tables): void {
            $name = self::tableOfMarker($line);
            if ($name !== null) {
                $tables[] = $name;
            }
        });

        return array_values(array_unique($tables));
    }

    /**
     * 未压缩导出文件里的表名（mysqldump 刚写出、还没 gzip 时统计用）。
     *
     * @return list<string>
     */
    public static function tablesInPlain(string $plain): array
    {
        $tables = [];
        $fh = @fopen($plain, 'rb');
        if ($fh === false) {
            return $tables;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $name = self::tableOfMarker((string)$line);
                if ($name !== null) {
                    $tables[] = $name;
                }
            }
        } finally {
            fclose($fh);
        }

        return array_values(array_unique($tables));
    }

    /** 表结构分节标记 → 表名；不是标记行返回 null */
    public static function tableOfMarker(string $line): ?string
    {
        if (preg_match(self::MARKER, $line, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
