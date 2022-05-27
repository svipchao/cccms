<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysRoute;
use cccms\services\{AuthService, TypesService};

/**
 * 路由管理
 * @sort 999
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
        $params = _validate($this->request->post(), 'sys_route|type_id,alias,url,name|ext,desc,status,false');
        // 判断类别是否属于菜单
        TypesService::instance()->isType((int)$params['type_id'], 3);
        if ($this->model->create($params)) {
            _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '添加失败'], _getEnCode());
        }
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
        $id = $this->request->delete('id/d', 0);
        if ($this->model->_delete(['id' => $id])) {
            _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '删除失败'], _getEnCode());
        }
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
        $params = _validate($this->request->put(), 'sys_route|id|type_id,alias,url,ext,name,desc,status,false');
        if (isset($params['type_id'])) {
            TypesService::instance()->isType((int)$params['type_id'], 3);
        }
        if ($this->model->update($params)) {
            _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '更新失败'], _getEnCode());
        }
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
        $params = $this->request->get(['page' => 1, 'limit' => 10, 'type_id' => 0]);
        [$types, $wheres] = TypesService::instance()->getTypesAndWheres(3, (int)$params['type_id']);
        $data = $this->model->where($wheres)->with('type')->_page($params);
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_route'),
                'types' => $types,
                'total' => $data['total'],
                'data' => $data['data']
            ]
        ], _getEnCode());
    }
}