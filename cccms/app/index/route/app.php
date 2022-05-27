<?php
declare (strict_types=1);

use think\facade\Route;
use app\admin\model\SysRoute;

$routes = SysRoute::mk()->where('status', 1)->_list();
foreach ($routes as $v) {
    Route::rule($v['alias'] . '$', $v['url'])->name($v['name'])->ext($v['ext']);
}
// 附件路由
Route::rule(config('cccms.storage.routePath', '/file/<code>'), 'index/file')->name('file');
// Route::rule('sitemap/<index>', 'index.extend/sitemap')->name('sitemap')->ext('xml');
