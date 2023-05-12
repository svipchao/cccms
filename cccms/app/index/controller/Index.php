<?php
declare(strict_types=1);

namespace app\index\controller;

use cccms\Base;

/**
 * 默认应用
 * @sort 999
 */
class Index extends Base
{
    /**
     * 首页
     * @auth false
     * @login false
     * @encode view
     * @methods GET
     */
    public function index(): string
    {
        // for ($i = 3; $i < 25; $i++) {
        //     echo "(" . $i . ", 0, '" . $i . "', '部门测试数据" . $i . "', '部门测试数据" . $i . "描述', 1)," . "<br>";
        //     echo "(" . $i . ", 0, '" . $i . "', '角色测试数据" . $i . "', '角色测试数据" . $i . "描述', 1)," . "<br>";
        //     echo "(" . $i . ", '用户测试数据" . $i . "', 'admin" . $i . "', '7fef6171469e80d32c0559f88b377245', 1)," . "<br>";
        // }
        // die;
        return '<!DOCTYPE html> <html lang="zh-cn"> <head> <meta charset="UTF-8" /> <meta name="viewport" content="width=device-width,user-scalable=no,initial-scale=1.0,maximum-scale=1.0,minimum-scale=1.0" /> <title>CCCMS</title> <style> * { padding: 0; margin: 0; background: #fff; font-family: "Microsoft Yahei", "Helvetica Neue", Helvetica, Arial, sans-serif; color: #333; font-size: 16px; } .system-message { padding: 24px 48px; } .system-message h1 { font-size: 100px; font-weight: normal; line-height: 120px; margin-bottom: 12px; } .system-message .msg { line-height: 1.8em; font-size: 36px; } </style> </head> <body> <div class="system-message"> <h1>:)</h1> <p class="msg">CCCMS</p> </div> </body> </html>';
    }
}
