<?php
declare (strict_types=1);

namespace cccms\extend;

class FileExtend
{
    /**
     * 获取文件列表
     * @param string $path 扫描目录
     * @param string $ext 有文件后缀
     * @return array
     */
    public static function scanDirList(string $path, string $ext = 'php'): array
    {
        $data = [];
        foreach (glob($path . '*') as $item) {
            if (is_dir($item)) {
                $data = array_merge($data, self::scanDirList($item . DIRECTORY_SEPARATOR));
            } elseif (is_file($item) && pathinfo($item, PATHINFO_EXTENSION) === $ext) {
                $data[] = strtr($item, '\\', '/');
            }
        }
        return $data;
    }

    /**
     * 获取文件数组
     * @param string $path 扫描目录
     * @param array $ignoresFile 额外数据
     * @param string $ext 有文件后缀
     * @return array
     */
    public static function scanDirArray(string $path, array $ignoresFile, string $ext = 'php'): array
    {
        $data = [];
        foreach (glob($path . '*') as $item) {
            $fileName = pathinfo($item)['filename'];
            if (in_array($fileName, $ignoresFile)) continue;
            if (is_dir($item)) {
                $data[$fileName] = self::scanDirArray($item . DIRECTORY_SEPARATOR, $ignoresFile);
            } elseif (is_file($item) && pathinfo($item, PATHINFO_EXTENSION) === $ext) {
                $data[$fileName] = strtr($item, '\\', '/');
            }
        }
        return $data;
    }
}