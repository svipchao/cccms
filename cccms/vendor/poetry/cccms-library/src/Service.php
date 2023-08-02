<?php
declare(strict_types=1);

namespace cccms;

use think\{App, Request, Container};

/**
 * 自定义服务基类
 * Class Service
 */
abstract class Service
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
     * Service constructor.
     * @param App $app
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->initialize();
    }

    /**
     * 初始化服务
     */
    protected function initialize(): void
    {
    }

    /**
     * 静态实例对象
     * @param array $var 实例参数
     * @param boolean $new 创建新实例
     * @return static
     */
    public static function instance(array $var = [], bool $new = false): Service
    {
        return Container::getInstance()->make(static::class, $var, $new);
    }
}
