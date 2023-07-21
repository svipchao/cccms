<?php
declare (strict_types=1);

namespace cccms;

use think\{App, Request, facade\Cache, facade\Env};
use cccms\service\AuthService;
use cccms\service\LogService;
use cccms\service\NodeService;

/**
 * 控制器基础类
 */
abstract class Base
{
    /**
     * 引入后台控制器的traits
     */
    use \cccms\traits\Controller;

    /**
     * 应用实例
     * @var App
     */
    protected $app;

    /**
     * @var Request
     */
    private $request;

    /**
     * 构造方法
     * @access public
     * @param App $app 应用对象
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;

        // APP_DEBUG 开发环境请手动开启
        if (Env::get('APP_DEBUG')) Cache::clear();

        // 设置过滤方法
        $this->request->filter(['htmlspecialchars', 'trim']);

        // 验证请求
//        $this->check();

        // 控制器初始化
        $this->init();
    }

    /**
     * 验证请求
     */
    protected function check(): array
    {
        $node = NodeService::instance()->getNode(_getNode());
        if (empty($node)) {
            _result(['code' => 404, 'msg' => '页面不存在'], _getEnCode());
        }
        // 判断访问方式是否符合注解
        if (!in_array(request()->method(), $node['methods'])) {
            _result(['code' => 405, 'msg' => '客户端请求中的方法被禁止'], _getEnCode());
        }
        // 判断返回编码是否符合注解
        if (!in_array(_getEnCode(), $node['encode'])) {
            _result(['code' => 405, 'msg' => '禁止此编码类型'], _getEnCode());
        }
        // 检测是否需要验证登录
        if ($node['login']) {
            // 判断是否登陆
            if(!AuthService::instance()->hasLogin()){
                _result(['code' => 401, 'msg' => '请登陆', 'url' => (string)url('/user/login')], _getEnCode());
            }
            // 判断是否需要验证权限 检查用户是否拥有权限
            if ($node['auth'] && !AuthService::instance()->hasUserAuth(_getNode())) {
                _result(['code' => 401, 'msg' => '权限不足'], _getEnCode());
            }
            // 记录日志
            if ($this->app->config->get('log.log_close') == 'true') {
                logService::instance()->log($node);
            }
        }
        return $userInfo ?? [];
    }

    // 初始化
    protected function init()
    {
    }
}