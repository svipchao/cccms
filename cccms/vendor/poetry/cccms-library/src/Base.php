<?php
declare(strict_types=1);

namespace cccms;

use stdClass;
use think\{App, Request};

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

        // 控制器初始化
        $this->init();

        $this->app->cache->clear();
    }

    // 初始化
    protected function init()
    {
    }
}