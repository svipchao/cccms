<?php
declare(strict_types=1);

return [
    'appName' => [
        'admin' => '基础系统',
        'index' => '默认应用',
    ],
    'resultPath' => app()->getRootPath() . 'vendor/poetry/cccms-library/src/cccms/views/result.tpl',
    'middleware' => [
        'think\middleware\SessionInit'
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