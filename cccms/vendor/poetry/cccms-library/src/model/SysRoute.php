<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysRoute extends Model
{
    public function searchRouteNameAttr($query, $value)
    {
        $query->where('route_name', '=', $value);
    }

    public function getRouteCate()
    {
        return SysRouteCate::mk()->cache('SysRouteCate')->_list();
    }
}
