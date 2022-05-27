<?php

declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysConfig;
use cccms\services\{AuthService, TypesService};

/**
 * 配置管理
 * @sort 999
 */
class Config extends Base
{
    public function init()
    {
        $this->model = SysConfig::mk();
    }

    /**
     * 添加配置
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods POST
     */
    public function create()
    {
        $params = _validate($this->request->post(), 'sys_config|type_id,key,val|desc,false');
        // 判断类别是否属于菜单
        TypesService::instance()->isType((int)$params['type_id'], 2);
        if ($this->model->create($params)) {
            _result(['code' => 200, 'msg' => '添加成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '添加失败'], _getEnCode());
        }
    }

    /**
     * 删除配置
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
     * 修改配置
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods PUT
     */
    public function update()
    {
        $data = array_map(function ($item) {
            return [
                'id' => $item['id'],
                'value' => $item['value'],
            ];
        }, $this->request->put());
        if ($this->model->saveAll($data)) {
            _result(['code' => 200, 'msg' => '更新成功'], _getEnCode());
        } else {
            _result(['code' => 403, 'msg' => '更新失败'], _getEnCode());
        }
    }

    /**
     * 配置列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $params = $this->request->get(['type_id' => 0]);
        [$types, $wheres] = TypesService::instance()->getTypesAndWheres(2, (int)$params['type_id']);
        $data = $this->model->where($wheres)->_list();
        foreach ($data as $key => &$val) {
            $val['configure'] = json_decode($val['configure'], true);
            if (empty($val['configure'])) {
                unset($data[$key]);
            } else {
                $val = array_merge($val['configure'], $val);
                unset($val['configure']);
            }
            if ($val['type'] === 'input-number') {
                $val['value'] = (int)$val['value'];
            }
            if ($val['type'] === 'multiple-select') {
                $val['value'] = explode(',', strtoupper($val['value']));
            }
            if ($val['type'] === 'switch') {
                $val['value'] = (int)$val['value'];
            }
        }
        _result([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'fields' => AuthService::instance()->fields('sys_config'),
                'types' => $types,
                'data' => $data
            ]
        ], _getEnCode());
    }
}
