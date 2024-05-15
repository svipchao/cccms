<?php
declare(strict_types=1);

namespace cccms;

use stdClass;
use think\{App, Request};
use cccms\services\{NodeService, LogService, ConfigService, UserService};

/**
 * 基础类
 */
abstract class Base extends stdClass
{
    /**
     * 应用实例
     * @var App
     */
    protected App $app;

    /**
     * @var Request
     */
    protected Request $request;

    /**
     * @var Model
     */
    protected Model $model;

    /**
     * 构造方法
     * @access public
     * @param App $app 应用对象
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;

        // 权限拦截
        $this->check();

        // 控制器初始化
        $this->init();

         $this->app->cache->clear();
    }

    // 初始化
    protected function init()
    {
    }

    /**
     * 权限拦截
     */
    public function check(): void
    {
        $node = NodeService::instance()->getCurrentNodeInfo();
        if (empty($node)) {
            _result(['code' => 404, 'msg' => '页面不存在']);
        }
        // 判断访问方式是否符合注解
        if (!in_array($this->request->method(), $node['methods'])) {
            _result(['code' => 405, 'msg' => '客户端请求中的方法被禁止']);
        }
        // 判断返回编码是否符合注解
        if (!in_array(_getEnCode(), $node['encode'])) {
            _result(['code' => 405, 'msg' => '禁止此编码类型']);
        }
        // 检测是否需要验证登录
        if ($node['login']) {
            // 判断是否登陆
            if (!UserService::instance()->getUserInfo('id')) {
                _result(['code' => 401, 'msg' => '请登陆']);
            }
            // 判断是否需要验证权限 检查用户是否拥有权限
            if ($node['auth'] && !UserService::instance()->isAuth()) {
                _result(['code' => 403, 'msg' => '权限不足，请申请【' . $node['currentPath'] . '】节点权限！']);
            }
            // 记录日志
            if (ConfigService::instance()->getConfig('log.logClose')) {
                LogService::instance()->log($node);
            }
        }
    }
}