<?php
declare (strict_types=1);

namespace app\admin\controller;

use cccms\Base;
use cccms\services\AuthService;
use cccms\model\{SysLog, SysUser};

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
        $this->model->whereTime('create_time', '<', '-30 day')->delete();
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
        $params = $this->app->request->get(['limit' => 10, 'page' => 1, 'user_id' => 0, 'like_user_str' => '']);
        if ($params['like_user_str']) {
            $res = SysUser::mk()->auth()->limit(10)->where([
                ['nickname|username', 'like', '%' . $params['like_user_str'] . '%'],
            ])->field('id,nickname,username')->_list();
            _result([
                'code' => 200,
                'msg' => 'success',
                'data' => $res
            ], _getEnCode());
        } else {
            $res = $this->model->auth($params['user_id'])->order(['id desc'])->with('user')->_page($params);
            _result([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'fields' => AuthService::instance()->fields('sys_log'),
                    'total' => $res['total'],
                    'data' => $res['data']
                ]
            ], _getEnCode());
        }
    }
}