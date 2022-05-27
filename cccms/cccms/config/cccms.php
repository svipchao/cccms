<?php

return [
    'appName' => [
        'admin' => '基础系统',
        'index' => '默认应用',
    ],
    'middleware' => [
        'think\middleware\SessionInit'
    ],
    'session' => [
        // Session过期时间
        'expire' => 86400,
    ],
    'user' => [
        // 用户类型
        'types' => [
            '后台用户',
            '前台会员'
        ]
    ],
    'storage' => [
        // 附件访问路由配置
        'routePath' => '/file/<code>'
    ]
];