<?php
declare(strict_types=1);

namespace cccms\services;

use think\Request;
use cccms\Service;
use cccms\model\{SysConfig, SysConfigCate};

class ConfigService extends Service
{
    protected $configs = [];

    /**
     * 初始化服务
     */
    protected function initialize(): void
    {
        $this->configs = $this->app->cache->get('SysConfigs', $this->handle());
    }

    protected function handle()
    {
        [$data, $configs] = [[], SysConfig::mk()->field('config_name,name,value')->_list()];
        foreach ($configs as $config) {
            if (str_contains($config['value'], ',')) {
                $config['value'] = array_filter(explode(',', $config['value']));
            }
            $data[$config['config_name']][$config['name']] = $config['value'];
        }
        $this->app->cache->set('SysConfigs', $data);
        return $data;
    }

    public function getConfig(string $name = '', mixed $default = null)
    {
        $configs = $this->configs;
        if (empty($configs)) return $default;
        if (empty($name)) return $configs;
        $name = explode('.', $name);
        // 按.拆分成多维数组进行判断
        foreach ($name as $val) {
            if (isset($configs[$val])) {
                $configs = $configs[$val];
            } else {
                return $default;
            }
        }
        return $configs;
    }

    public function getConfigCate()
    {
        return SysConfigCate::mk()->cache('SysConfigCate')->_list();
    }
}
