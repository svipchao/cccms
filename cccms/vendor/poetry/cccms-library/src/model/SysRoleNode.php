<?php

declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysRoleNode extends Model
{
    public function getUserNodes(int $user_id = 0): array
    {
        return $this->where('role_id', 'in', function ($query) use ($user_id) {
            $userDeptIds = array_column(SysDept::mk()->getUserDept($user_id), 'id');
            return $query->table('sys_dept_role')->field('role_id')->where('dept_id', 'in', $userDeptIds);
        })->where('role_id', 'not in', function ($query) {
            return $query->table('sys_role')->where('status', 0)->field('id');
        })->column('node');
    }
}
