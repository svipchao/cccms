<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\extend\ArrExtend;
use cccms\model\{SysRole, SysDept};
use cccms\services\{AuthService, NodeService};

/**
 * 部门管理
 * @sort 998
 */
class Dept extends Base
{
    public function init(): void
    {
        $this->model = SysDept::mk();
    }

    /**
     * 添加部门
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create(): void
    {
        $this->model->create(_validate('post.sys_dept.true', 'role_name|role_ids,nodes'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除部门
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
     * 修改部门
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update(): void
    {
        $this->model->update(_validate('put.sys_dept.true', 'id|role_ids,nodes'));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 部门列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index(): void
    {
        $depts = $this->model->with(['roles', 'nodesRelation'])->_list(callable: function ($data) {
            return array_map(function ($item) {
                $item['nodes'] = array_column($item['nodesRelation'], 'node');
                $item['role_ids'] = array_column($item['roles'], 'id');
                unset($item['roles'], $item['nodesRelation']);
                return $item;
            }, $data->toArray());
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_dept'),
            'roles' => SysRole::mk()->getAllOpenRole(true),
            'nodes' => NodeService::instance()->getAuthNodesTree(),
            'data' => ArrExtend::toTreeArray($depts, 'id', 'dept_id'),
        ]], _getEnCode());
    }
}
