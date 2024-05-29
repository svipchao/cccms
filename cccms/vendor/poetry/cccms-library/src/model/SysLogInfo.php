<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysLogInfo extends Model
{
    public function searchReqParamAttr($query, $value)
    {
        $query->where('log.req_param', 'like', "%" . $value . "%");
    }
}
