<?php

declare(strict_types=1);

namespace plugin\cccms\support\Scan;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 插件目录扫描的公共实现（`perm-scan` / `data-scope-check` / `write-guard-check` 共用）。
 *
 * 三个自检器此前各写一份「插件目录枚举 + PHP 文件遍历 + 相对路径」，
 * 口径只要有一处漂移，就会出现「perm-scan 扫到了、write-guard 没扫到」的静默偏差。
 *
 * 「CCCMS 业务插件」的判定统一为：有 `app/` 目录 + 声明 `db/menu.php`。
 * 这条口径同时排除了 `plugin/index` 这类公开前台插件（其控制器本就不声明鉴权注解）。
 */
final class PluginFs
{
    /**
     * 业务插件根目录（绝对路径，已排序）。
     *
     * @return array<int,string>
     */
    public static function pluginDirs(): array
    {
        $out = [];
        foreach (glob(base_path() . '/plugin/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_dir($dir . '/app') || !is_file($dir . '/db/menu.php')) {
                continue;
            }
            $out[] = $dir;
        }
        sort($out);

        return $out;
    }

    /**
     * 某目录下全部 PHP 文件（绝对路径，已排序）。
     *
     * @return array<int,string>
     */
    public static function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** 转成相对项目根的路径，输出更短 */
    public static function rel(string $path): string
    {
        $root = str_replace('\\', '/', dirname(rtrim(base_path(), '/\\'))) . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
