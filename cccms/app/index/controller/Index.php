<?php

declare(strict_types=1);

namespace app\index\controller;

use cccms\Base;
use cccms\extend\JwtExtend;
use think\facade\Db;
use think\Request;
use think\facade\View;

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
    public function index()
    {
        // halt(JwtExtend::getToken([
        //     'id' => 1,
        //     'nickname' => '超级管理员',
        //     'username' => 'admin',
        // ]));
        echo "index";
        // download();
        // redirect();
        // _result('123', _getEnCode('view'));
        // _result([
        //     'path' => './static/favicon.ico',
        //     'name' => '1.txt',
        // ]);
        // return '<!DOCTYPE html> <html lang="zh-cn"> <head> <meta charset="UTF-8" /> <meta name="viewport" content="width=device-width,user-scalable=no,initial-scale=1.0,maximum-scale=1.0,minimum-scale=1.0" /> <title>CCCMS</title> <style> * { padding: 0; margin: 0; background: #fff; font-family: "Microsoft Yahei", "Helvetica Neue", Helvetica, Arial, sans-serif; color: #333; font-size: 16px; } .system-message { padding: 24px 48px; } .system-message h1 { font-size: 100px; font-weight: normal; line-height: 120px; margin-bottom: 12px; } .system-message .msg { line-height: 1.8em; font-size: 36px; } </style> </head> <body> <div class="system-message"> <h1>:)</h1> <p class="msg">CCCMS</p> </div> </body> </html>';
    }

    /**
     * 创建数据
     * @auth false
     * @login false
     * @encode view
     * @methods GET
     */
    public function mock()
    {
        $userDeptPostUserIds = range(1, 10, 1);
        // 创建用户
        $user = [];
        for ($userI = 2; $userI < 10; $userI++) {
            $user[] = [
                'nickname' => '用户_' . $userI,
                'username' => 'user_' . $userI,
                'password' => md5('user_' . $userI),
            ];
        }
        Db::table('sys_user')->where('id', '>', 1)->delete();
        Db::table('sys_user')->insertAll($user);
        // 创建用户部门岗位表
        $userDeptPost = [];
        $userDeptPostUserIds = range(1, 10, 1);
        $userDeptPostDeptIds = range(1, 10, 1);
        $userDeptPostPostIds = range(1, 10, 1);
        for ($userDeptPostI = 0; $userDeptPostI < 100; $userDeptPostI++) {
            $userDeptPost[] = [
                'user_id' => $userDeptPostUserIds[array_rand($userDeptPostUserIds)],
                'dept_id' => $userDeptPostDeptIds[array_rand($userDeptPostDeptIds)],
                'post_id' => $userDeptPostPostIds[array_rand($userDeptPostPostIds)],
            ];
        }
        Db::table('sys_user_dept_post')->where('user_id', '>', 0)->delete();
        Db::table('sys_user_dept_post')->insertAll($userDeptPost);
    }
}
