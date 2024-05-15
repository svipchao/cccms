<?php
declare(strict_types=1);

namespace cccms\model;

use think\model\Pivot;
use think\model\relation\HasOne;

class SysAuth extends Pivot
{
    /**
     * 创建模型实例
     * @param array $data
     * @return SysAuth
     */
    public static function mk(array $data = []): SysAuth
    {
        return new static($data);
    }

    public function user(): HasOne
    {
        return $this->hasOne(SysUser::class, 'id', 'user_id');
    }

    public function role(): HasOne
    {
        return $this->hasOne(SysRole::class, 'id', 'role_id');
    }

    public function dept(): HasOne
    {
        return $this->hasOne(SysDept::class, 'id', 'dept_id');
    }
}
