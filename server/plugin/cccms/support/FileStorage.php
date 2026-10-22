<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\support\storage\StorageManager;
use Webman\Http\UploadFile;

/**
 * 附件存储门面：委托给当前配置的驱动（local / oss / cos / qiniu）。
 *
 * 注意：`url()` 使用**当前**驱动生成，切换存储后端后历史数据需迁移（见 README）。
 */
final class FileStorage
{
    /**
     * @param  int|null $maxBytes 本次允许的最大字节数；null = 用 `upload.max_size`
     *                           （分片上传合并后的文件上限更高，由调用方显式传入）
     * @return array{path:string,name:string,original_name:string,url:string,size:int,mime:string,ext:string,hash:string}
     */
    public static function upload(UploadFile $file, ?int $maxBytes = null): array
    {
        return StorageManager::driver()->upload($file, $maxBytes);
    }

    public static function delete(string $path): void
    {
        StorageManager::driver()->delete($path);
    }

    public static function url(string $path): string
    {
        return StorageManager::driver()->url($path);
    }

    /** 当前生效的驱动名（来自 upload.storage_driver） */
    public static function driver(): string
    {
        return StorageManager::current();
    }

    /**
     * 本地驱动的物理路径（不存在 / 越界返回 null）。
     *
     * `realpath` + 前缀围栏：`path` 来自库表，不能假定可信；私有附件下载也走这里，
     * 避免 `../` 把 `public/storage` 之外的文件读出去。云存储驱动返回 null（由调用方重定向）。
     */
    public static function localPath(string $path): ?string
    {
        $base = realpath(public_path() . '/storage');
        if ($base === false) {
            return null;
        }

        $real = realpath($base . '/' . ltrim($path, '/'));
        if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    /** 该扩展名是否属于图片（upload.image_ext），供前端决定是否显示缩略图 */
    public static function isImage(string $ext): bool
    {
        $imageExt = SysConfig::getList('upload.image_ext');
        if ($imageExt === []) {
            $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        }
        return in_array(strtolower($ext), $imageExt, true);
    }
}
