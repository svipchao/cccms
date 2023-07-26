<?php
declare (strict_types=1);

return [
    'config_id|配置' => 'integer',
    'config_id.integer' => '请选择正确的 %s',

    'name|名称' => 'require|alphaDash|length:1,32',
    'name.require' => '%s 不能为空',
    'name.alphaDash' => '%s 只能为字母和数字，下划线_及破折号-',
    'name.length' => '%s 长度至少 1 个字符或不超过 32 个字符',

    'label|标签' => 'require|alphaDash|length:1,255',
    'label.require' => '%s 不能为空',
    'label.alphaDash' => '%s 字母和数字，下划线_及破折号-',
    'label.length' => '%s 长度至少 1 个字符或不超过 255 个字符',

    'configure|参数' => 'require',
    'configure.require' => '%s 不能为空',

    'value|值' => 'length:0,255',
    'value.length' => '%s 长度不能超过 255 个字符',

    'sort|排序' => 'integer',
    'sort.integer' => '请录入正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
