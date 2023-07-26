<?php
declare(strict_types=1);

namespace cccms\extend;

class FormatExtend
{
    /**
     * 文件字节单位转换
     * @param int $size
     * @return string
     */
    public static function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        for ($i = 0; $size >= 1024 && $i < 4; $i++) $size /= 1024;
        return round($size, 2) . ' ' . $units[$i];
    }
}
