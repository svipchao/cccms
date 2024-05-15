<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysConfig extends Model
{
    protected $json = ['configure'];

    protected $jsonAssoc = true;

    public function searchConfigNameAttr($query, $value)
    {
        $query->where('config_name', '=', $value);
    }

    public function getFileLinkAttr($value, $data): string
    {
        return request()->domain() . '/file/' . ($data['file_code'] ?? '404');
    }
}
