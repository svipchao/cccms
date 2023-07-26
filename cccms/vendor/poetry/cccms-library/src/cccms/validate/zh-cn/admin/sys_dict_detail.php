<?php
declare (strict_types=1);

return [
    'dept_id|部门' => 'integer',
    'dept_id.integer' => '请选择正确的 %s',

    'name|名称' => 'require|chsDash|length:1,255',
    'name.require' => '%s 不能为空',
    'name.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'name.length' => '%s 长度至少 1 个字符或不超过 255 个字符',

    'label|标签' => 'require|alphaDash|length:1,255',
    'label.require' => '%s 不能为空',
    'label.alphaDash' => '%s 字母和数字，下划线_及破折号-',
    'label.length' => '%s 长度至少 1 个字符或不超过 255 个字符',

    'value|值' => 'length:0,255',
    'value.length' => '%s 长度不能超过 255 个字符',

    'sort|排序' => 'integer',
    'sort.integer' => '请录入正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
