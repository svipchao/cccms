<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\{SysUser, SysRole, SysDept};
use cccms\services\{UserService, AuthService};

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
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create(): void
    {
        $params = _validate('post.sys_user.true', 'nickname,username,password|role_ids,dept_ids');
        $params['password'] = md5($params['password']);
        $this->model->save($params);
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
        $params = _validate('put.sys_user.true', 'id|role_ids,dept_ids');
        if (!empty($params['password'])) {
            $params['password'] = md5($params['password']);
        } else {
            unset($params['password']);
        }
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
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index(): void
    {
        $params = _validate('get.sys_user.true', 'page,limit|user,tag,type,dept_id');
        $users = $this->model->_withSearch('user,tag,dept_id', [
            'user' => $params['user'] ?? null,
            'tag' => $params['tag'] ?? null,
            'type' => $params['type'] ?? null,
            'dept_id' => $params['dept_id'] ?? null,
        ])->with(['depts', 'roles'])->_page($params, false, function ($data) {
            $data = $data->toArray();
            $data['data'] = array_map(function ($item) {
                $item['dept_ids'] = array_column($item['depts'], 'id');
                $item['depts'] = array_column($item['depts'], 'dept_name');
                $item['role_ids'] = array_column($item['roles'], 'id');
                $item['roles'] = array_column($item['roles'], 'role_name');
                return $item;
            }, $data['data']);
            return $data;
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_user'),
            'roles' => SysRole::mk()->getAllOpenRole(true),
            'depts' => UserService::instance()->getUserDepts('tree'),
            'total' => $users['total'] ?? 0,
            'data' => $users['data'] ?? [],
        ]], _getEnCode());
    }
}
