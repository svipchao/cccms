<?php

declare(strict_types=1);

/** 用户模块相关文案。 */
return [
    // 校验 / 错误提示
    'not_found'                 => '用户不存在',
    'no_permission_view'        => '无权查看该用户',
    'no_permission_operate'     => '无权操作该用户',
    'username_required'         => '用户名不能为空',
    // 不写「已存在」：用户名是全局唯一键，确认存在与否会让 A 租户探知 B 租户的账号（P2-5）
    'username_exists'           => '该用户名不可用，请更换',
    'username_in_trash'         => '该用户名不可用；若由本租户删除，请到回收站恢复或彻底删除后重试',
    'password_required'         => '密码不能为空',
    'initial_password_required' => '缺少初始密码',
    'cannot_delete_self'        => '不能删除自己',
    'cannot_delete_super'       => '不能删除超管账号',
    'assign_target_required'    => '请选择要分配的角色 / 部门 / 岗位',

    // 导入
    'upload_csv_required'       => '请上传 CSV 文件',
    'csv_missing_username'      => 'CSV 缺少 username 列，请先下载导入模板',
    'import_line_prefix'        => '第 {line} 行：',
    'import_username_empty'     => '用户名为空',

    // 关联名称（导入解析时拼进错误提示，也用作导出列名）
    'label_role'                => '角色',
    'label_dept'                => '部门',
    'label_post'                => '岗位',
    'token_not_found'           => '{label}「{token}」不存在',
    'ref_not_in_tenant'         => '所选的{label}不属于当前租户',

    // 导出 / 导入模板
    'export_title'              => '用户列表',
    'template_title'            => '用户导入模板',
    'col_username'              => '用户名',
    'col_nickname'              => '昵称',
    'col_email'                 => '邮箱',
    'col_phone'                 => '手机号',
    'col_status'                => '状态',
    'col_create_time'           => '创建时间',
    'col_remark'                => '说明',
    'status_enabled'            => '启用',
    'status_disabled'           => '禁用',
    'template_sample_nickname'  => '张三',
    'template_pw_hint'          => '初始密码(至少6位)',
    'template_sample_role'      => '员工',
    'template_sample_dept'      => '研发部',
    'template_sample_post'      => '工程师',
    'template_hint'             => '已存在的用户名会被更新；新用户必须填 password；roles/depts/posts 按名称匹配、多值用逗号分隔、更新时留空则不改动',
];
