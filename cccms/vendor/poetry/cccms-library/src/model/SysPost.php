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

    public function getUserPost(int $userId = 0, int $status = 1)
    {
        if ($status == -1) {
            return $this->hasWhere('deptPostRelation', function ($query) use ($userId) {
                $query->where('user_id', '=', $userId);
            })->select()->toArray();
        } else {
            return $this->hasWhere('deptPostRelation', function ($query) use ($userId) {
                $query->where('user_id', '=', $userId);
            })->where('status', $status)->select()->toArray();
        }
    }
}
