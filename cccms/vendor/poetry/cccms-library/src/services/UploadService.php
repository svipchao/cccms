<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\{model\SysFileCate, Service, Storage};

class UploadService extends Service
{
    /**
     * 文件上传
     * @param int|string $pathOrId int 则为文件类型ID，string则为文件夹名称
     * @return array
     */
    public function upload(int|string $pathOrId = 0): array
    {
        $file = $this->request->file('file');
        if (!empty($file)) {
            $file = Storage::instance()->upload($file, $pathOrId);
            if (in_array($file['file_ext'], ['jpg', 'gif', 'png', 'bmp', 'jpeg', 'wbmp'])) {
                // 图片压缩
                $filePath = $this->app->getRootPath() . 'public/uploads/' . $file['file_url'];
                \think\Image::open($filePath)->save($filePath, $file['file_ext'], 90);
            }
            return $file;
        }
        return [];
    }
}
