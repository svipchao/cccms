<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use think\model\relation\BelongsToMany;
use think\model\relation\HasMany;

class SysDept extends Model
{
    public function getAllOpenDeptIds(): array
    {
        return $this->where('status', 1)->cache('allDeptOpenId')->column('id');
    }

    public function auth(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'dept_id', 'id');
    }

    public function role(): BelongsToMany
    {
        return $this->belongsToMany(SysRole::class, SysAuth::class, 'role_id', 'dept_id');
    }

    public function nodesRelation(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'dept_id', 'id')->where([
            ['dept_id', '<>', 0],
            ['node', '<>', ''],
        ]);
    }
}
