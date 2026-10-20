<?php

use plugin\cccms\support\DatabaseBootstrap;
use plugin\cccms\support\DatabaseHeartbeat;
use plugin\cccms\support\SecurityBootstrap;
use plugin\cccms\support\UploadBootstrap;

return [
    // 安全自检必须最先执行：密钥不合法时直接终止进程，不进入后续启动流程
    SecurityBootstrap::class,
    DatabaseBootstrap::class,
    // 长连接保活：避免 worker 内 PDO 连接被 wait_timeout / 中间件回收后报 2006 gone away
    DatabaseHeartbeat::class,
    // 上传临时目录兜底：防止 upload_tmp_dir 指向不存在/不可写目录时 tempnam 抛 notice 变 500
    UploadBootstrap::class,
];
