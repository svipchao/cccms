<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\model\SysLog;
use cccms\services\AuthService;

/**
 * 日志管理
 * @sort 990
 */
class Log extends Base
{
    public function init()
    {
        $this->model = SysLog::mk();
    }

    /**
     * 删除日志
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods DELETE
     */
    public function delete()
    {
        $this->model->destroy(function ($query) {
            $query->_withSearch(['user'], [
                'user' => '',
            ])->whereTime('log.create_time', '<', '-30 day');
        });
        _result(['code' => 200, 'msg' => '删除成功'], _getEnCode());
    }

    /**
     * 日志列表
     * @auth true
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        $params = _validate('get.sys_log', [
            'page' => 1,
            'limit' => 15,
            'user' => null,
        ]);
        $data = $this->model->with(['user'])->_withSearch('user', [
            'user' => $params['user']
        ])->order('id desc')->_page($params);
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_log'),
            'total' => $data['total'],
            'data' => $data['data']
        ]], _getEnCode());
    }
}