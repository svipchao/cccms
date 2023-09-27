<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysMenu;
use cccms\extend\ArrExtend;
use cccms\services\AuthService;

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
     * 修改菜单排序
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function updateSort()
    {
        $data = $this->request->put('data');
        foreach ($data as &$d) {
            $d = ['id' => $d['id'], 'sort' => $d['sort']];
        }
        $res = $this->model->saveAll($data);
        _result(['code' => 200, 'msg' => '修改排序成功'], _getEnCode());
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
        halt($this->app->config->get('session'));
        $data = $this->model->_withSearch('menu_id', [
            'menu_id' => $this->request->get('menu_id/d', null)
        ])->order('sort desc')->_list();
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_menu'),
            'data' => ArrExtend::toTreeArray($data, 'id', 'menu_id')
        ]], _getEnCode());
    }
}