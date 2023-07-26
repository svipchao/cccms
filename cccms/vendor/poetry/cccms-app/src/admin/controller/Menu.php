<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\SysMenu;
use cccms\Base;
use cccms\extend\ArrExtend;
use cccms\services\{AuthService, TypesService};

/**
 * 菜单管理
 * @sort 999
 */
class Menu extends Base
{
    public function init()
    {
        $this->model = SysMenu::mk();
    }

    /**
     * 添加菜单
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $this->model->create(_validate('post', 'sys_menu|type_id,name,url|true'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除菜单
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
     * 修改菜单
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $this->model->update(_validate('put', 'sys_menu|id|true'));
    }

    /**
     * 菜单列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $data = $this->model->with('type')->_withSearch('type_id', [
            'type_id' => $this->request->get('type_id/d', 0)
        ])->_list();
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::mk()->fields('sys_menu'),
            'types' => TypesService::mk()->getTypes(1),
            'data' => ArrExtend::toTreeList($data, 'id', 'menu_id')
        ]], _getEnCode());
    }
}