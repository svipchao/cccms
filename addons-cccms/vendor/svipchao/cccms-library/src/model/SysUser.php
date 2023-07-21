<?php
declare (strict_types=1);

namespace cccms\model;

use think\Model;
use think\model\relation\BelongsToMany;

class SysUser extends Model
{
    protected $append = ['toRole'];
    protected $autoWriteTimestamp = true;

    // 设置密码
    public function setPassWordAttr($value, $data)
    {
        if (empty($value)) {
            unset($data['password']);
            return $this->data($data, true);
        }
        return md5($value);
    }
    // 设置密码
    public function getPassWordAttr(): string
    {
        return '******';
    }

    public function toRole(): BelongsToMany
    {
        return $this->belongsToMany('SysRole', 'SysUserRole', 'role_id', 'user_id');
    }
}