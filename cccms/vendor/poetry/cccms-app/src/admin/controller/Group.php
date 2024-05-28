<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\{SysUser, SysGroup};
use cccms\Base;
use cccms\extend\ArrExtend;
use cccms\services\AuthService;

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
        $this->model->create(_validate('post', 'sys_group|group_name|admin_ids,role_ids,user_ids,true'));
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
        $this->model->_delete($this->request->delete('id/d', 0));
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
        $this->model->update(_validate('put', 'sys_group|id|admin_ids,role_ids,user_ids,true'));
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
        $user = $this->request->get('user/s');
        if ($user !== null) {
            $data = SysUser::mk()->_withSearch('user', [
                'user' => $user
            ])->limit(10)->_list();
            _result(['code' => 200, 'msg' => 'success', 'data' => $data], _getEnCode());
        } else {
            $groups = $this->model->where([
                ['id', 'in', AuthService::instance()->getUserGroups(true)]
            ])->_list();
            _result(['code' => 200, 'msg' => 'success', 'data' => [
                'fields' => AuthService::instance()->fields('sys_group'),
                'data' => ArrExtend::toTreeList($groups, 'id', 'group_id')
            ]], _getEnCode());
        }
    }
}
