<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\basic\BaseController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** 扫描控制器目录，返回 BaseController 子类 FQCN 列表。 */
final class ControllerScanner
{
    /**
     * @return array<int,string>
     */
    public static function scan(string $dir, string $baseNamespace = 'plugin\cccms\app\controller'): array
    {
        $classes = [];
        if (!is_dir($dir)) {
            return $classes;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = self::pathToClass($dir, $file->getPathname(), $baseNamespace);
            if ($class !== null && is_subclass_of($class, BaseController::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);
        return $classes;
    }

    private static function pathToClass(string $baseDir, string $file, string $baseNamespace): ?string
    {
        // PHP 8 起三参 substr 只会返回 string（不足长度时为空串），无需再判 false
        $rel = substr($file, strlen(rtrim($baseDir, DIRECTORY_SEPARATOR)) + 1, -4);
        if ($rel === '') {
            return null;
        }
        $rel = str_replace(DIRECTORY_SEPARATOR, '\\', $rel);
        return rtrim($baseNamespace, '\\') . '\\' . trim($rel, '\\');
    }
}
