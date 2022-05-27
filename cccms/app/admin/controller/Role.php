<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysRole;
use cccms\extend\ArrExtend;
use cccms\services\{NodeService, AuthService, UnlimitService};

/**
 * 角色管理
 * @sort 999
 */
class Role extends Base
{
    public function init()
    {
        $this->model = SysRole::mk();
    }

    /**
     * 添加角色
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $params = _validate($this->request->post(), 'sys_role|role_name|role_id,role_desc,nodes');
        // 判断父级角色是否属于当前用户所拥有的角色
        if (!AuthService::instance()->isUserRole((int)($params['role_id'] ?? 0))) {
            _result(['code' => 403, 'msg' => '未拥有该角色或角色不存在'], _getEnCode());
        }
        $res = $this->model->create($params);
        if (isset($params['nodes'])) {
            if (is_string($params['nodes'])) {
                $params['nodes'] = explode(',', $params['nodes']);
            }
            $res->nodes()->saveAll(ArrExtend::createTwoArray($params['nodes'], 'node'));
        }
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除角色
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods DELETE
     */
    public function delete()
    {
        $id = $this->request->delete('id/d', 0);
        // 判断当前用户是否拥有该角色
        $userHasRole = AuthService::instance()->isUserRole($id);
        if (!$userHasRole) {
            _result(['code' => 403, 'msg' => '未拥有该角色'], _getEnCode());
        }
        $roles = AuthService::instance()->getAllRoles();
        if (!UnlimitService::instance()->isDelete($id, $roles, 'id', 'role_id')) {
            _result(['code' => 403, 'msg' => '该角色包含子角色，禁止删除'], _getEnCode());
        }
        $res = $this->model->_read($id, false);
        if ($res->isEmpty()) {
            _result(['code' => 403, 'msg' => '角色不存在'], _getEnCode());
        }
        $res->together(['nodes'])->delete();
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
    }

    /**
     * 修改角色
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $params = _validate($this->request->put(), 'SysRole|id|role_name,role_id,role_desc,status,nodes');
        // 判断父级角色是否属于当前用户所拥有的角色
        if (isset($params['role_id']) && !AuthService::instance()->isUserRole((int)$params['role_id'])) {
            _result(['code' => 403, 'msg' => '未拥有该角色或角色不存在'], _getEnCode());
        }
        $roles = AuthService::instance()->getAllRoles();
        if (!UnlimitService::instance()->isUpdate($params, $roles, 'id', 'role_id')) {
            _result(['code' => 403, 'msg' => '父级角色属于当前角色的子级，禁止更新'], _getEnCode());
        }
        $res = $this->model->with('nodes')->_read($params['id'], false);
        if (isset($params['nodes'])) {
            if (is_string($params['nodes'])) {
                $params['nodes'] = explode(',', $params['nodes']);
            }
            $res->nodes()->delete();
            $res->nodes()->saveAll(ArrExtend::createTwoArray($params['nodes'], 'node'));
        }
        $res->update($params);
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 角色列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $ids = AuthService::instance()->getUserRoles(true);
        $roles = $this->model->with('nodes')->where('id', 'in', $ids)->_list();
        foreach ($roles as &$role) {
            $role['nodes'] = array_column($role['nodes'], 'node');
        }
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_role'),
                'data' => ArrExtend::toTreeList($roles, 'id', 'role_id')
            ]
        ], _getEnCode());
    }

    /**
     * 节点授权
     * @auth  true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function auth()
    {
        $role_id = $this->request->get('role_id/d', 0);
        $nodes = AuthService::instance()->getRoleNodes($role_id);
        // 全部节点
        $allNodes = NodeService::instance()->getNodesInfo();
        // 框架节点
        $frameNodes = NodeService::instance()->getFrameNodes();
        $nodes = array_intersect_key($allNodes, array_flip($nodes));
        foreach ($nodes as &$val) {
            // 移除无用数据
            unset($val['parentTitle'], $val['encode'], $val['methods'], $val['appName'], $val['auth'], $val['login'], $val['sort']);
        }
        // 将节点框架合并进权限节点中
        $nodes = array_merge($nodes, NodeService::instance()->setFrameNodes($nodes, $frameNodes));
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => ArrExtend::toTreeArray($nodes, 'currentNode', 'parentNode')
        ], _getEnCode());
    }
}