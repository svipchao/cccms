<?php

declare(strict_types=1);

/** 数据权限规则模块文案。 */
return [
    'not_found'                    => '规则不存在',
    'field_required'               => '请选择字段',
    'csv_required'                 => '请上传 CSV 文件',
    'csv_missing_name_column'      => 'CSV 缺少 name 列，请先下载导入模板',
    'row_name_required'            => '第 {line} 行：规则名为空',
    'row_failed'                   => '第 {line} 行：{message}',
    'bound_user_not_found'         => '绑定的用户 ID {id} 不存在',
    'bound_post_not_found'         => '绑定的岗位 ID {id} 不存在',
    'bound_role_not_found'         => '绑定的角色 ID {id} 不存在',
    'bound_dept_not_found'         => '绑定的部门 ID {id} 不存在',
    'unknown_action'               => '未知的规则动作：{action}',
    'invalid_table_name'           => '目标表名不合法',
    'target_table_not_controlled'  => '目标表 {table} 不在受控表内，请先在「受控表」里登记',
    'target_table_not_registered'  => '目标表 {table} 不在受控表内',
    'invalid_field_name'           => '字段名只能是字母、数字、下划线，且不能以数字开头',
    'unknown_operator'             => '未知的操作符：{operator}',
    'unknown_value_type'           => '未知的取值类型：{value_type}',
    'unknown_bind_mode'            => '未知的绑定关系：{mode}（只能是 or / and）',
    'field_not_in_table'           => '字段 {field} 不存在于表 {table}，请重新选择',
    'duplicate'                    => '已存在完全相同的规则（绑定对象、目标表、字段与动作一致）',
];
