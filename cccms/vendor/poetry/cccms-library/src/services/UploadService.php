<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\{Service, Storage};

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
            return Storage::instance()->upload($file, $pathOrId);
        }
        return [];
    }
}