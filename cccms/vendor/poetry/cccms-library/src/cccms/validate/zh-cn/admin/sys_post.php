<?php
declare (strict_types=1);

return [
    'dept_id|部门' => 'integer',
    'dept_id.integer' => '请选择正确的 %s',

    'post_name|岗位名称' => 'require|chsDash|length:5,32',
    'post_name.require' => '%s 不能为空',
    'post_name.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'post_name.length' => '%s 长度至少 5 个字符或不超过 32 个字符',

    'post_desc|岗位备注' => 'chsDash|length:0,255',
    'post_desc.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'post_desc.length' => '%s 长度不能超过 255 个字符',

    'range|权限范围' => 'require|between:0,2',
    'range.require' => '%s 不能为空',
    'range.between' => '请选择正确的 %s',

    'status|状态' => 'require|in:0,1',
    'status.require' => '%s 不能为空',
    'status.in' => '请选择正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
