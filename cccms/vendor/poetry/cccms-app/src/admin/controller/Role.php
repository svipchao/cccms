<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\extend\ArrExtend;
use cccms\model\{SysRole, SysAuth};
use cccms\services\{NodeService, AuthService, UserService};

/**
 * 角色管理
 * @sort 999
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
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create(): void
    {
        $this->model->create(_validate('put.sys_role.true', 'role_name'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除角色
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
     * 修改角色
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update(): void
    {
        $this->model->update(_validate('put.sys_role.true', 'id|nodes'));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 角色列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index(): void
    {
        $roles = $this->model->with('nodesRelation')->_list(callable: function ($data) {
            return array_map(function ($item) {
                $item['nodes'] = array_column($item['nodesRelation'], 'node');
                unset($item['nodesRelation']);
                return $item;
            }, $data->toArray());
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::mk()->fields('sys_role'),
            'data' => ArrExtend::toTreeList($roles, 'id', 'role_id')
        ]], _getEnCode());
    }

    /**
     * 节点授权
     * @auth  true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function auth(): void
    {
        $role_id = $this->request->get('role_id/d', 0);
        if ($role_id === 0) {
            $nodes = UserService::mk()->isAdmin() ?
                NodeService::mk()->getNodesInfo() :
                array_diff_key(NodeService::mk()->getNodesInfo(), array_flip(UserService::mk()->getUserNodes()));
        } else {
            $nodes = NodeService::mk()->setFrameNodes(SysAuth::mk()->where('role_id', $role_id)->column('node'));
        }
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => ArrExtend::toTreeArray($nodes, 'currentNode', 'parentNode')
        ], _getEnCode());
    }
}
