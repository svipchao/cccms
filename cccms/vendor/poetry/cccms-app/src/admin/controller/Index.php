<?php
declare(strict_types=1);

namespace app\admin\controller;

use cccms\Base;

/**
 * 控制台
 * @sort 999
 */
class Index extends Base
{
    /**
     * 控制台
     * @auth false
     * @login true
     * @encode json|jsonp|xml
     * @methods GET
     */
    public function index()
    {
        _result(['code' => 200, 'msg' => 'success', 'data' => []], _getEnCode());
    }
}