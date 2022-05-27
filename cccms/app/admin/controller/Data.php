<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysData;
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
        $params = _validate($this->request->post(), 'sys_data|table,role_id,field|where,value');
        // 权限验证
        AuthService::instance()->validateAuth($params);
        if ($this->model->create($params)) {
            _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '添加失败'], _getEnCode());
        }
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
        $params = _validate($this->request->delete(), 'sys_data|id,table,role_id,field');
        // 权限验证
        AuthService::instance()->validateAuth($params);
        if ($this->model->_delete(['id' => $params['id']])) {
            _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '删除失败'], _getEnCode());
        }
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
        $params = _validate($this->request->put(), 'sys_data|id,table,role_id,field|where,value');
        // 权限验证
        AuthService::instance()->validateAuth($params);
        if ($this->model->update($params)) {
            _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '添加失败'], _getEnCode());
        }
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
        $params = $this->app->request->get(['limit' => 15, 'page' => 1, 'role_id' => 0, 'table' => '']);
        $roles = AuthService::instance()->getUserRoles();
        $wheres = [];
        if ($params['role_id'] != 0) {
            $wheres[] = ['role_id', '=', (int)$params['role_id']];
        } else {
            $wheres[] = ['role_id', 'in', array_column($roles, 'id')];
        }
        if (!empty($params['table'])) {
            $wheres[] = ['table', '=', (string)$params['table']];
        }

        $tableInfo = InitService::instance()->getTables();
        $fieldsInfo = [];
        foreach ($tableInfo as $table => &$table_name) {
            unset($table_name['rules']);
            $fields = [];
            foreach ($table_name['fields'] as $field => $field_name) {
                $fieldsInfo[$table][$field] = explode('|', $field_name)[1] ?? $field;
                $fields[] = [
                    'field' => $field,
                    'field_name' => $fieldsInfo[$table][$field],
                ];
            }
            $table_name['fields'] = $fields;
        }

        $res = $this->model->where($wheres)->with('role')->_page($params);
        foreach ($res['data'] as &$data) {
            $table = $tableInfo[$data['table']] ?? [];
            $data['table_name'] = $table['table_name'] ?? '未知';
            $fields = $fieldsInfo[$data['table']] ?? [];
            $data['field_name'] = $fields[$data['field']] ?? '|未知';
        }
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'total' => $res['total'],
                'roles' => $roles,
                'table' => $tableInfo,
                'data' => $res['data'],
            ]
        ]);
    }
}