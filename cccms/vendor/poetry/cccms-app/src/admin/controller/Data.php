<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\SysData;
use cccms\Base;
use cccms\services\{AuthService, InitService};

/**
 * 数据权限
 * @sort 999
 */
class Data extends Base
{
    public function init()
    {
        $this->model = SysData::mk();
    }

    /**
     * 添加权限
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $this->model->create(_validate('post', 'sys_data|role_id,table,field|where,value'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 删除权限
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods DELETE
     */
    public function delete()
    {
        $params = _validate('delete', 'sys_data|id,table,role_id,field');
        $this->model->_delete(['id' => $params['id']]);
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
    }

    /**
     * 修改权限
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $this->model->update(_validate('put', 'sys_data|id,table,role_id,field|where,value'));
        _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
    }

    /**
     * 查看权限
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $params = _validate('get', ['sys_data', '', [
            'limit' => 15,
            'page' => 1,
            'role_id' => 0,
            'table' => null,
        ]]);
        $tableInfo = InitService::instance()->getTables();
        $data = $this->model->with('role')->_withSearch('role_id,table', [
            'role_id' => $params['role_id'],
            'table' => $params['table']
        ])->_page($params, false, function ($data) use ($tableInfo) {
            $data = $data->toArray();
            $data['data'] = array_map(function ($item) use ($tableInfo) {
                $tableInfo = $tableInfo[$item['table']];
                $item['table_name'] = $tableInfo['table_name'] ?? '未知';
                $item['field_name'] = $tableInfo['fields'][$item['field']] ?? '未知';
                return $item;
            }, $data['data']);
            return $data;
        });
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'roles' => AuthService::instance()->getUserRoles(),
            'table' => $tableInfo,
            'data' => $data['data'],
            'total' => $data['total']
        ]]);
    }
}