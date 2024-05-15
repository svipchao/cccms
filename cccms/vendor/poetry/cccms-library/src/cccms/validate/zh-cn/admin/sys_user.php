<?php
declare (strict_types=1);

return [
    'nickname|昵称' => 'require|chsDash|length:2,32|unique:sys_user',
    'nickname.require' => '%s 不能为空',
    'nickname.chsDash' => '%s 只能是汉字、字母、数字和下划线_及破折号-',
    'nickname.length' => '%s 长度至少 2 个字符或不超过 32 个字符',
    'nickname.unique' => '%s 已存在',

    'username|用户名' => 'require|alphaDash|length:5,32|unique:sys_user',
    'username.require' => '%s 不能为空',
    'username.alphaDash' => '%s 只能为字母和数字，下划线_及破折号-',
    'username.length' => '%s 长度至少 5 个字符或不超过 32 个字符',
    'username.unique' => '%s 已存在',

    'password|密码' => 'require|alphaDash|length:5,32',
    'password.require' => '%s 不能为空',
    'password.alphaDash' => '%s 只能为字母和数字，下划线_及破折号-',
    'password.length' => '%s 长度至少 5 个字符或不超过 32 个字符',

    'phone|手机号码' => 'mobile',
    'phone.mobile' => '请输入有效的 %s',

    'email|电子邮箱' => 'email',
    'email.email' => '请输入有效的 %s',

    'status|状态' => 'require|in:0,1',
    'status.require' => '%s 不能为空',
    'status.in' => '请选择正确的 %s',

    'create_time|创建时间' => 'date',
    'create_time.date' => '请录入正确的 %s',

    'update_time|创建时间' => 'date',
    'update_time.date' => '请录入正确的 %s',
];
