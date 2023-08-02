<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\SysTypes;
use cccms\Base;
use cccms\services\AuthService;

/**
 * 类别管理
 * @sort 999
 */
class Types extends Base
{
    public function init()
    {
        $this->model = SysTypes::mk();
    }

    /**
     * 添加类别
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $this->model->create(_validate('post', 'sys_types||true'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除类别
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
     * 修改类别
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $this->model->update(_validate('put', 'sys_types|id|true'));
        _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
    }

    /**
     * 类别列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $params = _validate('get', ['sys_types', '', [
            'limit' => 10,
            'page' => 1,
            'type' => null
        ]]);
        $data = $this->model->_withSearch('type', ['type' => $params['type']])->_page($params);
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_types'),
            'type' => config('cccms.types.type'),
            'total' => $data['total'],
            'data' => $data['data'],
        ]], _getEnCode());
    }
}