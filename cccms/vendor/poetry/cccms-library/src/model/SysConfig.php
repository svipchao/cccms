<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysConfig extends Model
{
    protected $json = ['configure'];

    protected $jsonAssoc = true;

    public function searchCateNameAttr($query, $value): void
    {
        $query->where('cate_name', '=', $value);
    }
}
