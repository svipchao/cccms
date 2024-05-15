<?php
declare (strict_types=1);

return [
    'leader_id|负责人' => 'integer',
    'leader_id.integer' => '请选择正确的 %s',

    'dept_id|父级部门' => 'integer|different:id',
    'dept_id.integer' => '请选择正确的 %s',
    'dept_id.different' => '禁止选择自身为 %s',

    'dept_name|部门名称' => 'require|chsDash|length:2,32',
    'dept_name.require' => '%s 不能为空',
    'dept_name.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'dept_name.length' => '%s 长度至少 2 个字符或不超过 32 个字符',

    'dept_desc|部门备注' => 'chsDash|length:0,255',
    'dept_desc.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'dept_desc.length' => '%s 长度不能超过 255 个字符',

    'status|状态' => 'require|in:0,1',
    'status.require' => '%s 不能为空',
    'status.in' => '请选择正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
