<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysLogInfo extends Model
{
    protected $json = ['req_params', 'upd_params', 'req_result'];

    protected $jsonAssoc = true;

    public function searchReqParamAttr($query, $value)
    {
        $query->where('log.req_param', 'like', "%" . $value . "%");
    }
}
