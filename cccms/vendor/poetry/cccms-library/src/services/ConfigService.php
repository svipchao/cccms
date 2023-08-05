<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\Service;
use cccms\model\SysConfig;
use think\Request;

class ConfigService extends Service
{
    protected $configs = [];

    /**
     * 初始化服务
     */
    protected function initialize(): void
    {
        // $this->handle();
        dump($this->app->cache->get('SysConfigs', []));
        die;
        $this->configs = $this->app->cache->get('SysConfigs', $this->handle());
    }

    public function handle()
    {
        [$data, $configs] = [[], SysConfig::mk()->field('config_name,name,value')->_list()];
        foreach ($configs as $config) {
            if (str_contains($config['value'], ',')) {
                $config['value'] = array_filter(explode(',', $config['value']));
            }
            $data[$config['config_name']][$config['name']] = $config;
        }
        $this->app->cache->set('SysConfigs', $data);
        return $data;
    }
}
