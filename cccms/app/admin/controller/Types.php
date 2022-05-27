<?php
declare (strict_types=1);

namespace app\admin\controller;

use think\facade\Db;
use cccms\Base;
use cccms\model\SysTypes;
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
        $params = _validate($this->request->post(), 'sys_types|type,name,alias,sort');
        if ($this->model->create($params)) {
            _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '添加失败'], _getEnCode());
        }
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
        $id = $this->request->delete('id/d', 0);
        $typeInfo = $this->model->_read($id);
        if (empty($typeInfo)) {
            _result(['code' => 404, 'msg' => '类别不存在'], _getEnCode());
        }
        // 有子项禁止删除 这里只能写死
        // 1:菜单,2:配置,3:路由,4:附件
        $tableName = ['', 'sys_menu', 'sys_config', 'sys_route', 'sys_file'][$typeInfo['type']];
        if (!empty($tableName)) {
            $res = Db::table($tableName)->where(['type_id' => $id])->findOrEmpty();
            if (!empty($res)) {
                _result(['code' => 403, 'msg' => '类别下数据不为空'], _getEnCode());
            }
        }
        if ($this->model->_delete(['id' => $id])) {
            _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '删除失败'], _getEnCode());
        }
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
        $params = _validate($this->request->put(), 'sys_types|id,type,name,alias,sort');
        if ($this->model->update($params)) {
            _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '更新失败'], _getEnCode());
        }
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
        $params = $this->request->get(['limit' => 10, 'page' => 1, 'type' => 0]);
        $wheres = $params['type'] ? ['type' => $params['type']] : [];
        $data = $this->model->where($wheres)->_page($params);
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_types'),
                'total' => $data['total'],
                'data' => $data['data'],
                'type' => ['未知', '菜单', '配置', '路由', '附件'],
            ]
        ], _getEnCode());
    }
}