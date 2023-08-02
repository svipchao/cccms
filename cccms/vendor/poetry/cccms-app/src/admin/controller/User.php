<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysUser;
use cccms\services\AuthService;

/**
 * 用户管理
 * @sort 999
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
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create(): void
    {
        $this->model->create(_validate('post.sys_user.true', 'nickname,username,password'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除用户
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods DELETE
     */
    public function delete(): void
    {
        $this->model->_delete($this->request->delete('id/d', 0));
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
    }

    /**
     * 更新用户
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update(): void
    {
        $this->model->update(_validate('put', 'sys_user|id|group_ids,true', [
            'password|密码' => 'alphaNum|length:5,32',
            'token|Token' => 'alphaNum|length:32'
        ]));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 用户列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index(): void
    {
        $params = _validate('get.sys_user', [
            'page' => 1,
            'limit' => 10,
            'dept_id' => null,
            'user' => '',
        ]);
        $users = $this->model->_withSearch('user,dept_id', [
            'user' => $params['user'],
            'dept_id' => $params['dept_id'],
        ])->_page($params);
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_user'),
                'total' => $users['total'] ?? 0,
                'data' => $users['data'] ?? [],
            ]
        ], _getEnCode());
    }
}
