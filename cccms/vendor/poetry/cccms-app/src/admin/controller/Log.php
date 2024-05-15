<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\SysLog;
use cccms\Base;
use cccms\services\AuthService;

/**
 * 日志管理
 * @sort 999
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
        $params = _validate('get', ['sys_log', '', [
            'page' => 1,
            'limit' => 15,
            'user' => '',
            'req_method' => null,
            'req_param' => null,
        ]]);
        $data = $this->model->with(['user'])->_withSearch('user,req_method,req_param', [
            'user' => $params['user'],
            'req_method' => $params['req_method'] ?: null,
            'req_param' => $params['req_param'] ?: null,
        ])->order('log.id desc')->_page($params);
        _result(['code' => 200, 'msg' => 'success', 'data' => [
            'fields' => AuthService::instance()->fields('sys_log'),
            'total' => $data['total'],
            'data' => $data['data']
        ]], _getEnCode());
    }
}