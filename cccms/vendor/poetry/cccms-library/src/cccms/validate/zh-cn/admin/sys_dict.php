<?php
declare (strict_types=1);

return [
    'dict_name|字典名称' => 'require|alphaDash|length:1,32',
    'dict_name.require' => '%s 不能为空',
    'dict_name.alphaDash' => '%s 只能为字母和数字，下划线_及破折号-',
    'dict_name.length' => '%s 长度至少 1 个字符或不超过 32 个字符',

    'dict_desc|字典描述' => 'chsDash|length:0,255',
    'dict_desc.chsDash' => '%s 只能为汉字、字母、数字和下划线_及破折号-',
    'dict_desc.length' => '%s 长度不能超过 255 个字符',

    'sort|排序' => 'integer',
    'sort.integer' => '请录入正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
