<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\extend\ArrExtend;
use cccms\model\{SysRole, SysDept};
use cccms\services\{UserService, AuthService};

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
     * @encode json
     * @methods POST
     */
    public function create(): void
    {
        $params = _validate('post.sys_dept.true', 'role_name|role');
        $this->model->save($params);
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除部门
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
     * 修改部门
     * @auth true
     * @login true
     * @encode json
     * @methods PUT
     */
    public function update(): void
    {
        $params = _validate('put.sys_dept.true', 'id|role');
        $user = $this->model->where('id', $params['id'])->findOrEmpty();
        if ($user->isEmpty()) {
            _result(['code' => 403, 'msg' => '部门不存在'], _getEnCode());
        }
        $user->save($params);
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 部门列表
     * @auth true
     * @login true
     * @encode json
     * @methods GET
     */
    public function index(): void
    {
        $params = $this->request->param(['recycle' => null]);
        $dept = $this->model;
        if (!UserService::isAdmin()) {
            $dept = $dept->hasWhere('userDeptRelation', function ($query) {
                $query->where('user_id', '=', UserService::getUserId());
            });
        }
        $dept = $dept->with(['role' => function ($query) {
            $query->field('id,role_name');
        }])->_list($params);
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_dept'),
            'data' => ArrExtend::toTreeList($dept, 'id', 'dept_id'),
            'role' => ArrExtend::toTreeList(SysRole::mk()->getUserRoleAll(), 'id', 'role_id'),
        ]], _getEnCode());
    }
}
