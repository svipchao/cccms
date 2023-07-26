<?php
declare(strict_types=1);

use think\facade\Route;

if ($this->app->http->getName() === 'index') {
    // 附件路由
    Route::rule('/file/:code', 'index/file/file')->name('file');
}
