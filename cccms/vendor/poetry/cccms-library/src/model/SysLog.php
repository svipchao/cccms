<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use think\model\relation\HasOne;

class SysLog extends Model
{
    protected $hidden = ['user'];

    public function user(): HasOne
    {
        return $this->hasOne(SysUser::class, 'id', 'user_id')->bind([
            'username',
            'nickname'
        ]);
    }

    public function info(): HasOne
    {
        return $this->hasOne(SysLogInfo::class, 'log_id', 'id');
    }

    public function searchUserAttr($query, $value)
    {
        $query->where('user_id', 'in', $value);
    }

    public function searchReqMethodAttr($query, $value)
    {
        $query->where('log.req_method', '=', $value);
    }

    public function searchReqParamAttr($query, $value)
    {
        $query->where('log.req_param', 'like', "%" . $value . "%");
    }
}
