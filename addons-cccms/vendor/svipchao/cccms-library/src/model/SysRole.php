<?php
declare (strict_types=1);

namespace cccms\model;

use think\Model;
use think\model\relation\BelongsToMany;
use think\model\relation\HasOne;

class SysRole extends Model
{
    protected $append = ['nodes'];

    protected $autoWriteTimestamp = true;

    public function getNodesAttr($value){
        return array_filter(explode(',',$value));
    }

    public function setNodesAttr($value): string
    {
        return implode(',',$value);
    }

    public function toOneUser(): hasOne
    {
        return $this->hasOne('SysUser', 'user_id', 'id');
    }

    public function toUser(): BelongsToMany
    {
        return $this->belongsToMany('SysUser', 'SysUserRole', 'user_id', 'role_id');
    }
}