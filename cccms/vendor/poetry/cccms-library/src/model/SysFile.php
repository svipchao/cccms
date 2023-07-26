<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\extend\FormatExtend;

class SysFile extends Model
{
    public function getFileSizeAttr($value): string
    {
        return FormatExtend::formatBytes($value);
    }

    public function getFileLinkAttr($value, $data): string
    {
        return request()->domain() . '/file/' . ($data['file_code'] ?? '404');
    }
}
