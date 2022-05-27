<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysMenu;
use cccms\extend\ArrExtend;
use cccms\services\{AuthService, MenuService, TypesService, UnlimitService};

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
        $params = _validate($this->request->post(), 'sys_menu|type_id,name,url|menu_id,icon,sort,false');
        // 判断类别是否属于菜单
        TypesService::instance()->isType((int)$params['type_id'], 1);
        if ($this->model->create($params)) {
            _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '添加失败'], _getEnCode());
        }
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
        $params = _validate($this->request->delete(), 'sys_menu|id,type_id');
        $menus = MenuService::instance()->getTypeMenus($params['type_id']);
        if (!UnlimitService::instance()->isDelete($params, $menus, 'id', 'menu_id')) {
            _result(['code' => 403, 'msg' => '存在子级数据 或 数据不存在'], _getEnCode());
        }
        if ($this->model->_delete(['id' => $params['id']])) {
            _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '删除失败'], _getEnCode());
        }
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
        $params = _validate($this->request->put(), 'sys_menu|id|type_id,name,url,menu_id,icon,sort,status,false');
        if (isset($params['menu_id'])) {
            $menus = MenuService::instance()->getTypeMenus($params['type_id']);
            if (!UnlimitService::instance()->isUpdate($params, $menus, 'id', 'menu_id')) {
                _result(['code' => 403, 'msg' => '父级菜单属于当前子级数据 或 父级菜单不存在'], _getEnCode());
            }
        }
        if (isset($params['type_id'])) {
            TypesService::instance()->isType((int)$params['type_id'], 1);
        }
        if ($this->model->update($params)) {
            _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '更新失败'], _getEnCode());
        }
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
        $params = $this->request->get(['page' => 1, 'limit' => 10, 'type_id' => 0]);
        [$types, $wheres] = TypesService::instance()->getTypesAndWheres(1, (int)$params['type_id']);
        $data = $this->model->where($wheres)->with('type')->_list();
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_menu'),
                'types' => $types,
                'data' => ArrExtend::toTreeList($data, 'id', 'menu_id')
            ]
        ], _getEnCode());
    }
}