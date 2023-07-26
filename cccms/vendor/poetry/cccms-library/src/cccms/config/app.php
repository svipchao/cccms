<?php
declare(strict_types=1);

return [
    // 开启应用快速访问
    'app_express' => true,
    // 异常页面的模板文件
    'exception_tmpl' => app()->getRootPath() . 'vendor/poetry/cccms-library/src/cccms/views/exception.tpl',
];
