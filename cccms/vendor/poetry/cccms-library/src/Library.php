<?php

declare(strict_types=1);

namespace cccms;

use think\{Request, Service};
use cccms\support\Url;
use cccms\services\{BaseService, NodeService};
use cccms\support\middleware\{Cors, MultiApp, Log};

class Library extends Service
{
    /**
     * 静态应用实例
     * @var App
     */
    public static $sapp;

    // 启动服务
    public function boot(): void
    {
        // 静态应用赋值
        static::$sapp = $this->app;

        // 绑定URL类
        $this->app->bind(['think\route\Url' => Url::class]);
    }

    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->app->event->listen('HttpRun', function (Request $request) {
            // 配置默认输入过滤
            $request->filter([function ($value) {
                return is_string($value) ? _xss_safe($value) : $value;
            }]);
            // 设置扩展配置文件
            $this->setConfig();
            // 设置扩展验证文件
            $this->setValidate();
            // 设置数据库指定查询对象
            $this->setDatabaseQuery();
            // 设置全局中间件
            $this->app->middleware->import(array_merge_recursive(
                [Cors::class, MultiApp::class, Log::class],
                $this->app->config->get('cccms.middleware', [])
            ));
        });
    }

    /**
     * 设置扩展验证文件
     * @return void
     */
    private function setValidate(): void
    {
        $rootPath = $this->app->getRootPath();
        $lang = $this->app->lang->getLangSet();
        $toScanFileArray = array_merge(
            BaseService::instance()->scanDirArray($rootPath . 'vendor/poetry/cccms-library/src/cccms/validate/' . $lang . '*'),
            BaseService::instance()->scanDirArray($rootPath . 'cccms/validate/' . $lang . '*')
        );
        foreach ($toScanFileArray as $file) {
            $this->app->config->load($file, 'validate_' . pathinfo($file, PATHINFO_FILENAME));
        }
    }

    /**
     * 设置扩展配置文件
     * @return void
     */
    private function setConfig(): void
    {
        $rootPath = $this->app->getRootPath();
        $toScanFileArray = array_merge(
            BaseService::instance()->scanDirArray($rootPath . 'vendor/poetry/cccms-library/src/cccms/config/*'),
            BaseService::instance()->scanDirArray($rootPath . 'cccms/config/*')
        );
        foreach ($toScanFileArray as $file) {
            $this->app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }
    }

    /**
     * 设置数据库指定查询对象
     * @return void
     */
    private function setDatabaseQuery(): void
    {
        // 设置数据库指定查询对象
        $database = $this->app->config->get('database', []);
        $database['connections'][$database['default']]['query'] = '\\cccms\\Query';
        $database['connections'][$database['default']]['fields_cache'] = true;
        $this->app->config->set($database, 'database');
    }
}
