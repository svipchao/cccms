<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use think\model\relation\HasMany;

class SysConfigCate extends Model
{
    public function detail(): HasMany
    {
        return $this->hasMany(SysConfig::class, 'cate_name', 'cate_name');
    }
}
