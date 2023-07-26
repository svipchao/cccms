<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\Service;

class BaseService extends Service
{
    /**
     * 获取文件数组
     * @param string $path 扫描目录
     * @param string $suffix 后缀
     * @param array $ignoresFile 额外数据
     * @return array
     */
    public function scanDirArray(string $path, string $suffix = '.php', array $ignoresFile = []): array
    {
        $data = [];
        foreach (glob($path) as $item) {
            $fileName = pathinfo($item)['filename'];
            if (in_array($fileName, $ignoresFile)) continue;
            if (is_dir($item)) {
                $data = array_merge($this->scanDirArray($item . DIRECTORY_SEPARATOR . '/*', $suffix, $ignoresFile), $data);
            } else {
                if (strstr($item, $suffix)) $data[] = $this->getPathHandle($item);
            }
        }
        return $data;
    }

    public function getPathHandle($path = '', $ds = '/')
    {
        return str_replace(["\\", "\/", "//", "/"], $ds, $path);
    }
}
