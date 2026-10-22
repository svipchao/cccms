<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 下载响应头的小工具。
 *
 * `Content-Disposition` 同时给两个名字，覆盖两类浏览器：
 * - `filename`：ASCII 兜底名（老浏览器只认这个，非 ASCII 直接写进去会乱码）；
 * - `filename*`：RFC 5987 的 UTF-8 名（现代浏览器优先取它，中文名不乱码）。
 *
 * `Response::download()` 只写 `filename="原名"`，中文名在浏览器里是乱码 —— 附件原名
 * 基本都是中文，因此统一走这里，与 `Csv` / `ExportTask` 同一套编码口径。
 */
final class Download
{
    public static function disposition(string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'download';

        // ASCII 兜底名：非可打印字符逐个替换成下划线（/u 让它按字符而非字节替换）
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $name) ?? 'download';
        // 引号、反斜杠、换行会截断 / 注入响应头
        $ascii = str_replace(['"', '\\', "\r", "\n", "\0"], '_', $ascii);

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $ascii === '' ? 'download' : $ascii,
            rawurlencode($name)
        );
    }
}
