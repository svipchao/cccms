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
        $this->model->create(_validate('post', 'sys_menu|parent_id,name,url|true'));
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
        $this->model->update(_validate('put.sys_menu.true', 'id'));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
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
        $cate = $this->model->where(['parent_id' => 0, 'menu_id' => 0])->_list();
        $parent_id = $this->request->get('parent_id/d', null);
        $parent_id = $parent_id ?: ($cate[0]['id'] ?? 0);
        $data = $this->model->_withSearch('parent_id', [
            'parent_id' => $parent_id
        ])->order('sort desc')->_list();
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_menu'),
            'cate' => $cate,
            'data' => ArrExtend::toTreeList($data, 'id', 'menu_id')
        ]], _getEnCode());
    }
}
