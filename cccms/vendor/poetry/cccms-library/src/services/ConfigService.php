<?php
declare(strict_types=1);

namespace cccms\services;

use think\Request;
use cccms\Service;
use cccms\model\{SysConfig, SysConfigCate};

class ConfigService extends Service
{
    protected static function handle(): array
    {
        $data = static::$app->cache->get('SysConfigs', []);
        if (empty($data)) {
            [$data, $configs] = [[], SysConfig::mk()->field('cate_name,name,value')->_list()];
            foreach ($configs as $config) {
                if (str_contains($config['value'], ',')) {
                    $config['value'] = array_filter(explode(',', $config['value']));
                }
                $data[$config['cate_name']][$config['name']] = $config['value'];
            }
            static::$app->cache->set('SysConfigs', $data);
        }
        return $data;
    }

    public static function getConfig(string $name = '', mixed $default = null)
    {
        $configs = self::handle();
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

    public function getConfigCate(): array
    {
        return SysConfigCate::mk()->cache('SysConfigCate')->_list();
    }
}
