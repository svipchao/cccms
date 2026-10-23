<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use RuntimeException;
use Webman\Http\Response;
use Webman\Http\UploadFile;

/**
 * CSV 导入 / 导出（通用能力）。
 *
 * 为什么用 CSV 而不是 xlsx：不引入第三方库、不依赖 zip / xml 扩展，
 * 且 Excel、WPS、Numbers 都能直接打开。导出带 UTF-8 BOM，避免中文在 Excel 里乱码。
 *
 * 两条安全约定：
 *   - **公式注入防护**：以 `= + - @` 开头的单元格在导出时前置单引号，
 *     否则恶意内容会在打开表格时被当成公式执行；
 *   - **导入规模限制**：单次导入限制行数与文件大小，避免一个超大文件打爆内存。
 */
final class Csv
{
    /** 导入允许的最大行数（不含表头） */
    public const MAX_ROWS = 5000;

    /** 导入允许的最大字节数 */
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const BOM = "\xEF\xBB\xBF";

    /**
     * 生成 CSV 并作为附件下载。
     *
     * @param string                            $filename 不带扩展名的文件名
     * @param array<int,string>                 $headers  表头
     * @param array<int,array<int|string,mixed>> $rows     数据行（键会被忽略，按顺序输出）
     */
    public static function download(string $filename, array $headers, array $rows): Response
    {
        $ascii = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename) ?: 'export';
        $name  = $filename . '.csv';

        return new Response(200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            // 同时给出 ASCII 兜底名与 RFC 5987 的 UTF-8 名，兼容各浏览器
            'Content-Disposition' => sprintf(
                'attachment; filename="%s.csv"; filename*=UTF-8\'\'%s',
                $ascii,
                rawurlencode($name)
            ),
            'Cache-Control'       => 'no-store',
        ], self::encode($headers, $rows));
    }

    /**
     * 编码为 CSV 文本（含 BOM）。
     *
     * @param array<int,string>                  $headers
     * @param array<int,array<int|string,mixed>> $rows
     */
    public static function encode(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('无法创建临时流');
        }

        fputcsv($handle, array_map([self::class, 'safe'], $headers));
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'safe'], array_values($row)));
        }

        rewind($handle);
        $content = (string)stream_get_contents($handle);
        fclose($handle);

        return self::BOM . $content;
    }

    /**
     * 解析上传的 CSV 文件。
     *
     * 返回「表头 => 值」的关联数组列表；首行会被自动去掉 BOM。
     *
     * @return array{headers:array<int,string>,rows:array<int,array<string,string>>}
     */
    public static function parse(UploadFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === '' || !is_file($path)) {
            throw new ApiException('上传文件读取失败', 422);
        }
        if ((int)$file->getSize() > self::MAX_BYTES) {
            throw new ApiException('CSV 文件过大（上限 ' . (self::MAX_BYTES / 1024 / 1024) . 'MB）', 422);
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ApiException('上传文件读取失败', 422);
        }

        $headers = [];
        $rows    = [];
        $line    = 0;

        try {
            while (($cells = fgetcsv($handle)) !== false) {
                // fgetcsv 对空行返回 [null]（永远不会返回 []），只判前者即可
                if ($cells === [null]) {
                    continue;   // 跳过空行
                }

                if ($line === 0) {
                    $cells[0] = self::stripBom((string)($cells[0] ?? ''));
                    $headers  = array_map(static fn ($v): string => trim((string)$v), $cells);
                    $line++;
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new ApiException('CSV 行数超过上限（' . self::MAX_ROWS . ' 行）', 422);
                }

                $item = [];
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $item[$header] = trim((string)($cells[$index] ?? ''));
                }
                $rows[] = $item;
                $line++;
            }
        } finally {
            fclose($handle);
        }

        if ($headers === [] || count($headers) < 2) {
            throw new ApiException('CSV 缺少表头（第一行必须是列名）', 422);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * 流式写一行（含公式注入防护）。
     *
     * 给「异步导出逐行写文件」用：`encode()` / `download()` 需要一次性拿到全部行，
     * 超大数据量下会占满内存；这里把「写一行」抽成公开方法，调用方按批次喂数据，
     * 表头也经同一套 `safe()` 处理（普通表头不以危险字符开头，不会误伤）。
     *
     * @param resource           $handle
     * @param array<int,mixed>   $cells
     */
    public static function writeRow($handle, array $cells): void
    {
        fputcsv($handle, array_map([self::class, 'safe'], array_values($cells)));
    }

    /** 公式注入防护：危险前缀加单引号，Excel 会按纯文本处理 */
    private static function safe(mixed $value): string
    {
        $text = (string)$value;
        if ($text !== '' && str_contains('=+-@', $text[0])) {
            return "'" . $text;
        }

        return $text;
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, self::BOM) ? substr($value, 3) : $value;
    }
}
