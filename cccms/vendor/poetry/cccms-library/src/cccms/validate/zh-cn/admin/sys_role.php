<?php
declare (strict_types=1);

return [
    'role_id|父级角色' => 'integer|different:id',
    'role_id.integer' => '请选择正确的 %s',
    'role_id.different' => '禁止选择自身为 %s',

    'role_name|角色名称' => 'require|chsDash|length:2,32',
    'role_name.require' => '%s 不能为空',
    'role_name.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'role_name.length' => '%s 长度至少 2 个字符或不超过 32 个字符',

    'role_desc|角色备注' => 'chsDash|length:0,255',
    'role_desc.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'role_desc.length' => '%s 长度不能超过 255 个字符',

    'status|状态' => 'require|in:0,1',
    'status.require' => '%s 不能为空',
    'status.in' => '请选择正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
