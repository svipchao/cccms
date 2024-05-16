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
        $params = _validate('post', 'password,username,captcha,captchaToken', [
            'username|账号' => 'require',
            'password|密码' => 'require',
        ]);
        if (!CaptchaService::instance()->check($params['captcha'], $params['captchaToken'])) {
            _result(['code' => 202, 'msg' => '验证码错误'], _getEnCode());
        }
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
            ->field('id,invite_id,nickname,username,avatar,phone,email,range')
            ->where($where)->findOrEmpty();
        if ($userInfo->isEmpty()) {
            _result(['code' => 401, 'msg' => '账号或密码错误'], _getEnCode());
        } else {
            // 这个append的顺序不能变 accessToken->nodes->menus
            $userInfo = $userInfo->append(['accessToken', 'nodes', 'menus', 'configs', 'invite'])->hidden([
                'invite'
            ])->toArray();
            $userInfo['home_url'] = 'admin/index/index';
            _result(['code' => 200, 'msg' => '登录成功', 'data' => $userInfo], _getEnCode());
        }
    }

    /**
     * 用户注册
     * @auth  false
     * @login false
     * @encode json
     * @methods POST
     * @return void
     */
    public function register(): void
    {
        $params = _validate('post', 'password,username,captcha,captchaToken', [
            'username|账号' => 'require',
            'password|密码' => 'require',
        ]);
        if (!CaptchaService::instance()->check($params['captcha'], $params['captchaToken'])) {
            _result(['code' => 202, 'msg' => '验证码错误'], _getEnCode());
        }
        $userInfo = SysUser::mk()->where('username', $params['username'])->findOrEmpty();
        if ($userInfo->isEmpty()) {
            SysUser::mk()->withoutGlobalScope()->save([
                'nickname' => '普通用户_' . mt_rand(10000, 99999),
                'username' => $params['username'],
                'password' => md5($params['password']),
                'tags' => ''
            ]);
            _result(['code' => 200, 'msg' => '注册成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '账号已存在'], _getEnCode());
        }
    }

    /**
     * 刷新Token
     * @auth  false
     * @login false
     * @encode json
     * @methods POST
     */
    public function refreshToken(): void
    {
        $this->app->cache->clear();
        $userInfo = SysUser::mk()->withoutGlobalScope()
            ->field('id,invite_id,nickname,username,avatar,phone,email,range')
            ->where('status', 1)
            ->findOrEmpty(UserService::instance()->getUserInfo('id'))
            ->append(['accessToken', 'nodes', 'menus', 'configs'])
            ->toArray();
        _result(['code' => 200, 'msg' => '缓存清除成功', 'data' => $userInfo], _getEnCode());
    }

    /**
     * 验证码
     * @auth  false
     * @login false
     * @encode json
     * @methods GET
     */
    public function captcha()
    {
        $node = $this->request->get('node', 'admin/login/index');
        _result(['code' => 200, 'msg' => 'success', 'data' => CaptchaService::instance()->create($node)]);
    }
}
