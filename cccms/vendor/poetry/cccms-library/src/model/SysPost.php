<?php

declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use think\model\relation\HasMany;

class SysPost extends Model
{
    public function deptPostRelation(): HasMany
    {
        return $this->hasMany(SysUserDeptPost::class, 'dept_id', 'id');
    }

    public function getUserPost(int $userId)
    {
        return $this->hasWhere('deptPostRelation', function ($query) use ($userId) {
            $query->where('user_id', '=', $userId);
        })->where('status', 1)->select()->toArray();
    }
}
