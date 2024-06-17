<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysRole;
use cccms\extend\ArrExtend;
use cccms\services\{NodeService, AuthService, UserService};

/**
 * 角色管理
 * @sort 996
 */
class Role extends Base
{
    public function init(): void
    {
        $this->model = SysRole::mk();
    }

    /**
     * 添加角色
     * @auth true
     * @login true
     * @encode json
     * @methods POST
     */
    public function create(): void
    {
        $params = _validate('post.sys_role.true', 'role_name|nodes');
        $this->model->save($params);
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除角色
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
     * 修改角色
     * @auth true
     * @login true
     * @encode json
     * @methods PUT
     */
    public function update(): void
    {
        $params = _validate('put.sys_role.true', 'id|nodes');
        $user = $this->model->where('id', $params['id'])->findOrEmpty();
        if ($user->isEmpty()) {
            _result(['code' => 403, 'msg' => '角色不存在'], _getEnCode());
        }
        $user->save($params);
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 角色列表
     * @auth true
     * @login true
     * @encode json
     * @methods GET
     */
    public function index(): void
    {
        $params = $this->request->param(['recycle' => null]);
        $role = $this->model;
        if (!UserService::isAdmin()) {
            $role = $role->where('id', 'in', function ($query) {
                $userDeptIds = UserService::instance()->getUserDeptIds();
                return $query->table('sys_dept_role')->field('role_id')->where('dept_id', 'in', $userDeptIds);
            });
        }
        $role = $role->with(['nodesRelation'])->_list($params, function ($data) {
            return array_map(function ($item) {
                $item['nodes'] = array_column($item['nodesRelation'], 'node');
                unset($item['nodesRelation']);
                return $item;
            }, $data->toArray());
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_role'),
            'data' => ArrExtend::toTreeList($role, 'id', 'role_id'),
            'nodes' => NodeService::instance()->getAuthNodesTree(),
        ]], _getEnCode());
    }
}
