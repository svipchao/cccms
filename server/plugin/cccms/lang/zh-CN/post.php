<?php

declare(strict_types=1);

/** 岗位模块相关文案。 */
return [
    'not_found'     => '岗位不存在',
    'code_required' => '岗位编码不能为空',
    // 同 user.username_exists：不给「是否存在」的明确答复
    'code_exists'   => '该岗位编码不可用，请更换',
    'code_in_trash' => '该岗位编码不可用；若由本租户删除，请到回收站恢复或彻底删除后重试',
    'has_users'     => '岗位下存在用户，无法删除',
];
