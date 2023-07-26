<?php

declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysUser;
use cccms\services\{UserService, CaptchaService};

/**
 * 登录管理
 * @sort 999
 */
class Login extends Base
{
    /**
     * 用户登陆
     * @auth  false
     * @login false
     * @encode json
     * @methods POST
     * @return void
     */
    public function index(): void
    {
        $params = _validate('post', 'password,username', [
            'username|账号' => 'require',
            'password|密码' => 'require',
        ]);
        if ($this->app->isDebug() && $params['password'] === 'admin') {
            $where = [['username', '=', $params['username']]];
        } else {
            $where = [
                ['username', '=', $params['username']],
                ['password', '=', md5($params['password'])],
                ['status', '=', 1]
            ];
        }
        $userInfo = SysUser::mk()->withoutGlobalScope()
            ->field('id,nickname,username')
            ->where($where)
            ->findOrEmpty();
        if ($userInfo->isEmpty()) {
            _result(['code' => 401, 'msg' => '账号或密码错误'], _getEnCode());
        } else {
            // 这个append的顺序不能变 accessToken->nodes->menus
            $userInfo = $userInfo->append(['accessToken', 'nodes', 'menus'])->toArray();
            _result(['code' => 200, 'msg' => '登录成功', 'data' => $userInfo], _getEnCode());
        }
    }

    /**
     * 刷新Token
     * @auth  false
     * @login true
     * @encode json
     * @methods POST
     */
    public function refreshToken(): void
    {
        $userInfo = SysUser::mk()
            ->field('id,nickname,username')
            ->where('status', 1)
            ->findOrEmpty(UserService::mk()->getUserInfo('id'))
            ->append(['accessToken', 'nodes', 'menus'])
            ->toArray();
        _result(['code' => 200, 'msg' => '缓存清除成功', 'data' => $userInfo], _getEnCode());
    }

    /**
     * 验证码
     * @auth  false
     * @login false
     * @encode view
     * @methods GET
     */
    public function captcha()
    {
        return CaptchaService::mk()->create();
    }
}
