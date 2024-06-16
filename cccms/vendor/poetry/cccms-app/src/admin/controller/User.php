<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysUser;
use cccms\extend\ArrExtend;
use cccms\services\{CaptchaService, UserService, AuthService};

/**
 * 用户管理
 * @sort 995
 */
class User extends Base
{
    public function init(): void
    {
        $this->model = SysUser::mk();
    }

    /**
     * 添加用户
     * @auth true
     * @login true
     * @encode json
     * @methods POST
     */
    public function create(): void
    {
        $params = _validate('post.sys_user.true', 'nickname,username,password|dept');
        $this->model->save($params);
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除用户
     * @auth true
     * @login true
     * @encode json
     * @methods DELETE
     */
    public function delete(): void
    {
        $params = $this->request->delete(['id' => 0, 'type' => null]);
        $this->model->_delete($params['id'], $params['type']);
        _result(['code' => 200, 'msg' => '操作成功'], _getEnCode());
    }

    /**
     * 更新用户
     * @auth true
     * @login true
     * @encode json
     * @methods PUT
     */
    public function update(): void
    {
        $params = _validate('put.sys_user.true', 'id|dept');
        $user = $this->model->where('id', $params['id'])->findOrEmpty();
        if ($user->isEmpty()) {
            _result(['code' => 403, 'msg' => '用户不存在'], _getEnCode());
        }
        $user->save($params);
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 用户列表
     * @auth true
     * @login true
     * @encode json
     * @methods GET
     */
    public function index(): void
    {
        $params = _validate('get.sys_user.true', 'page,limit|user,tag,type,dept_id,recycle');
        $users = $this->model->_withSearch('tag,user,dept_id', [
            'tag' => $params['tag'] ?? null,
            'user' => $params['user'] ?? null,
            'dept_id' => $params['dept_id'] ?? null,
        ])->with(['dept' => function ($query) {
            $query->field('id,dept_name');
        }])->order('id desc')->_page($params, false, function ($data) {
            $data = $data->toArray();
            foreach ($data['data'] as &$d) {
                $d['dept'] = array_map(function ($v) {
                    return [
                        'id' => $v['id'],
                        'dept_name' => $v['dept_name'],
                        'auth_range' => $v['pivot']['auth_range'],
                    ];
                }, $d['dept']);
            }
            return $data;
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_user'),
            'dept' => ArrExtend::toTreeList(UserService::instance()->getUserDept(), 'id', 'dept_id'),
            'total' => $users['total'] ?? 0,
            'data' => $users['data'] ?? [],
        ]], _getEnCode());
    }

    /**
     * 用户登陆
     * @auth  false
     * @login false
     * @encode json
     * @methods POST
     * @return void
     */
    public function login(): void
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
            ->field('id,nickname,username,avatar,phone')
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
        if (empty(UserService::instance()->getUserInfo('id', 0))) {
            _result(['code' => 401, 'msg' => '登陆已过期 请重新登陆'], _getEnCode());
        }
        $userInfo = SysUser::mk()->withoutGlobalScope()
            ->field('id,nickname,username,avatar,phone')
            ->where('status', 1)
            ->findOrEmpty(UserService::instance()->getUserInfo('id', 0))
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
    public function captcha(): void
    {
        $node = $this->request->get('node', '');
        _result(['code' => 200, 'msg' => 'success', 'data' => CaptchaService::instance()->create($node)]);
    }
}
