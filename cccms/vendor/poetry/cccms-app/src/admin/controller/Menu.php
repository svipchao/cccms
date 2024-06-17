<?php

declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysMenu;
use cccms\extend\ArrExtend;
use cccms\services\AuthService;

/**
 * 菜单管理
 * @sort 993
 */
class Menu extends Base
{
    public function init(): void
    {
        $this->model = SysMenu::mk();
    }

    /**
     * 添加菜单
     * @auth true
     * @login true
     * @encode json
     * @methods POST
     */
    public function create(): void
    {
        $params = _validate('post.sys_menu.true', 'parent_id,name,url');
        $this->model->save($params);
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除菜单
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
     * 修改菜单
     * @auth true
     * @login true
     * @encode json
     * @methods PUT
     */
    public function update(): void
    {
        $params = _validate('put.sys_menu.true', 'id|type,data');
        if (isset($params['type']) && $params['type'] == 'sort') {
            $data = $this->request->put('data');
            foreach ($data as &$d) {
                $d = ['id' => $d['id'], 'sort' => $d['sort']];
            }
            $this->model->saveAll($data);
        } else {
            $user = $this->model->where('id', $params['id'])->findOrEmpty();
            if ($user->isEmpty()) {
                _result(['code' => 403, 'msg' => '菜单不存在'], _getEnCode());
            }
            $this->model->update($params);
        }
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 菜单列表
     * @auth true
     * @login true
     * @encode json
     * @methods GET
     */
    public function index(): void
    {
        $params = $this->request->get(['parent_id' => null, 'recycle' => null]);
        $cate = $this->model->where(['parent_id' => 0, 'menu_id' => 0])->_list();
        $params['parent_id'] = $params['parent_id'] ?: ($cate[0]['id'] ?? 0);
        $data = $this->model->_withSearch('parent_id', [
            'parent_id' => $params['parent_id']
        ])->order('sort desc')->_list($params);
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_menu'),
            'data' => ArrExtend::toTreeList($data, 'id', 'menu_id'),
            'cate' => $cate,
        ]], _getEnCode());
    }
}
