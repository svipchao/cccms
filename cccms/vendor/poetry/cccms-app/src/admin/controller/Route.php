<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysRoute;
use cccms\services\{AuthService, ConfigService};

/**
 * 路由管理
 * @sort 992
 */
class Route extends Base
{
    public function init()
    {
        $this->model = SysRoute::mk();
    }

    /**
     * 添加路由
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $this->model->create(_validate('post.sys_route.true', 'route_name'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除路由
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
     * 修改路由
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $this->model->update(_validate('put.sys_route.true', 'id'));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 路由列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $data = $this->model->_withSearch('route_name', [
            'route_name' => $this->request->get('route_name', 'default')
        ])->_list();
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_route'),
            'cates' => $this->model->getRouteCate(),
            'data' => $data
        ]], _getEnCode());
    }
}