<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\Service;
use cccms\model\SysConfig;

class ConfigService extends Service
{
    protected $configs = [];

    /**
     * 初始化服务
     */
    protected function initialize(): void
    {
        $configs = $this->app->cache->get('SysConfigs', []);
        if (empty($configs)) $this->getConfigs();
        $this->configs = [123];
    }

    public function getConfigs()
    {
        $configs = SysConfig::mk()->_list();
        halt($configs);
    }
}