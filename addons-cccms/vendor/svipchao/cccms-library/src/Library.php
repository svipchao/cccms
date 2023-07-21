<?php
declare (strict_types=1);

namespace cccms;

use think\Service;
use cccms\extend\FileExtend;
use cccms\service\ConfigService;

class Library extends Service
{
    // 初始化服务
    public function boot()
    {
        // 设置配置文件
        $files = FileExtend::scanDirList($this->app->getRootPath() . 'cccms/config/');
        foreach ($files as $file) {
            $this->app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }

        // 生成配置文件
        if (!$this->app->config->get('validate')) {
//            ConfigService::instance()->createConfigFile();
//            ConfigService::instance()->createValidateRuleFile();
//            // 生成配置文件后执行刷新
//            header('Location: '.$_SERVER['REQUEST_URI']);
        }

        // 设置全局中间件
        $this->app->middleware->import($this->app->config->get('base.middleware',[]));

        // 设置过滤规则
        $this->app->request->filter(['trim', 'strip_tags', 'htmlspecialchars']);
    }
}