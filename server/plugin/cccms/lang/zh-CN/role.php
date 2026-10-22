<?php

declare(strict_types=1);

/** 角色模块相关文案。 */
return [
    'not_found'              => '角色不存在',
    'code_required'          => '角色标识不能为空',
    // 同 user.username_exists：不给「是否存在」的明确答复
    'code_exists'            => '该角色标识不可用，请更换',
    'code_in_trash'          => '该角色标识不可用；若由本租户删除，请到回收站恢复或彻底删除后重试',
    'code_generate_failed'   => '无法自动生成角色标识，请手动指定',
    'parent_not_found'       => '父角色不存在',
    'inherit_depth_exceeded' => '角色继承深度不能超过 5 层',
    'cannot_delete_super'    => '不能删除超管角色',
    'has_children'           => '存在子角色，无法删除',
    'copy_suffix'            => ' 副本',
];
