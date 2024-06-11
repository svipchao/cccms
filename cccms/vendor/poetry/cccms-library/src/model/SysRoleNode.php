<?php

declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysRoleNode extends Model
{
    public function getNodes(int $user_id = 0): array
    {
        return $this->where('role_id', 'in', function ($query) use ($user_id) {
            $userDeptIds = array_column(SysDept::mk()->getUserDept($user_id), 'id');
            $userPostIds = array_column(SysPost::mk()->getUserPost($user_id), 'id');
            return $query->table('sys_post_role')->field('role_id')->union([
                'SELECT role_id FROM sys_post_role where post_id in (' . join(',', $userDeptIds) . ')',
                'SELECT role_id FROM sys_dept_role where dept_id in (' . join(',', $userPostIds) . ')',
            ]);
        })->column('node');
    }
}
