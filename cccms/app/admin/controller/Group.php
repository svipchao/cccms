<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\extend\ArrExtend;
use cccms\model\{SysRole, SysGroup};
use cccms\services\{AuthService, UnlimitService};

/**
 * 组织管理
 * @sort 999
 */
class Group extends Base
{
    public function init()
    {
        $this->model = SysGroup::mk();
    }

    /**
     * 添加组织
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $params = _validate($this->request->post(), 'sys_group|group_name|group_id,group_desc,roles');
        // 判断父级角色是否属于当前用户所拥有的角色
        if (!AuthService::instance()->isUserGroup((int)($params['group_id'] ?? 0))) {
            _result(['code' => 403, 'msg' => '未拥有该组织或组织不存在'], _getEnCode());
        }
        $res = $this->model->create($params);
        if (isset($params['roles'])) {
            if (is_string($params['roles'])) {
                $params['roles'] = explode(',', $params['roles']);
            }
            $res->roles()->saveAll($params['roles']);
        }
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除组织
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods DELETE
     */
    public function delete()
    {
        $id = $this->request->delete('id/d', 0);
        // 判断当前用户是否拥有该角色
        $userHasRole = AuthService::instance()->isUserGroup($id);
        if (!$userHasRole) {
            _result(['code' => 403, 'msg' => '未拥有该组织'], _getEnCode());
        }
        $groups = AuthService::instance()->getAllGroups();
        if (!UnlimitService::instance()->isDelete($id, $groups, 'id', 'group_id')) {
            _result(['code' => 403, 'msg' => '该组织包含子组织，禁止删除'], _getEnCode());
        }
        $res = $this->model->_read($id, false);
        if ($res->isEmpty()) {
            _result(['code' => 403, 'msg' => '组织不存在'], _getEnCode());
        }
        $res->roles()->detach();
        $res->delete();
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
    }

    /**
     * 修改组织
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $params = _validate($this->request->put(), 'sys_group|id|group_name,group_id,group_desc,status,roles');
        // 判断父级角色是否属于当前用户所拥有的角色
        if (isset($params['group_id']) && !AuthService::instance()->isUserGroup((int)$params['group_id'])) {
            _result(['code' => 403, 'msg' => '未拥有该组织或组织不存在'], _getEnCode());
        }
        $groups = AuthService::instance()->getAllGroups();
        if (!UnlimitService::instance()->isUpdate($params, $groups, 'id', 'group_id')) {
            _result(['code' => 403, 'msg' => '父级组织属于当前组织的子级，禁止更新'], _getEnCode());
        }
        $res = $this->model->_read($params['id'], false);
        if (isset($params['roles'])) {
            if (is_string($params['roles'])) {
                $params['roles'] = explode(',', $params['roles']);
            }
            // 删除中间表数据
            $res->roles()->detach();
            $res->roles()->saveAll($params['roles']);
        }
        $res->update($params);
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 组织列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $ids = AuthService::instance()->getUserGroups(true);
        $groups = $this->model->with('roles')->where('id', 'in', $ids)->_list();
        foreach ($groups as &$group) {
            $group['roles'] = array_column($group['roles'], 'id');
        }
        $roleIds = AuthService::instance()->getUserRoles(true);
        $roles = SysRole::mk()->where('id', 'in', $roleIds)->_list();
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_group'),
                'roles' => ArrExtend::toTreeList($roles, 'id', 'role_id'),
                'data' => ArrExtend::toTreeList($groups, 'id', 'group_id')
            ]
        ], _getEnCode());
    }
}