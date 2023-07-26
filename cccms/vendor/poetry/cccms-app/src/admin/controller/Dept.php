<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysDept;
use cccms\extend\ArrExtend;
use cccms\services\AuthService;

/**
 * 部门管理
 * @sort 999
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
        $this->model->create(_validate('post.sys_dept.true', 'role_name'));
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
        $this->model->update(_validate('put.sys_dept.true', 'id'));
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
        $roles = $this->model->with(['role', 'nodesRelation'])->_list(callable: function ($data) {
            return array_map(function ($item) {
                $item['nodes'] = array_column($item['nodesRelation'], 'node');
                unset($item['nodesRelation']);
                return $item;
            }, $data->toArray());
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::mk()->fields('sys_dept'),
            'data' => ArrExtend::toTreeArray($roles, 'id', 'dept_id')
        ]], _getEnCode());
    }
}
