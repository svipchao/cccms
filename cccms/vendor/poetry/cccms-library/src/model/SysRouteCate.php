<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use think\model\relation\HasMany;

class SysRouteCate extends Model
{
    public function detail(): HasMany
    {
        return $this->hasMany(SysRoute::class, 'route_name', 'route_name');
    }
}
