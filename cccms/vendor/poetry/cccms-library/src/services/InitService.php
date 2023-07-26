<?php
declare(strict_types=1);

namespace cccms\services;

use think\facade\Db;
use cccms\Service;
use cccms\model\SysConfig;
use cccms\extend\StrExtend;

class InitService extends Service
{
    public function getConfigs()
    {
        $data = $this->app->cache->get('SysConfigs', []);
        if (empty($data)) {
            $configRes = SysConfig::mk()->with(['detail'])->_list();
            // 根据类别分组
            $configs = array_column($configRes, 'detail', 'config_name');
            // 组合配置文件格式
            foreach ($configs as $key => $val) {
                $items = [];
                foreach ($val as $v1) {
                    // 判断是否需要二次分割
                    if (str_contains($v1['value'], '|')) {
                        $item = array_filter(explode('|', $v1['value']));
                        foreach ($item as $v2) {
                            $v2 = array_filter(explode(',', $v2));
                            $items[$v1['name']][$v2[0]] = $v2[1];
                        }
                    } elseif (str_contains($v1['value'], ',')) {
                        $items[$v1['name']] = array_filter(explode(',', $v1['value']));
                    } else {
                        $items[$v1['name']] = $v1['value'];
                    }
                }
                $data[$key] = $items;
            }
            $this->app->cache->set('SysConfigs', $data);
        }
        return $data;
    }

    /**
     * 获取表信息
     * @param string $tableName
     * @return array
     */
    public function getTables(string $tableName = ''): array
    {
        $data = $this->app->cache->get('Tables', []);
        if (empty($data)) {
            $tables = Db::query('SHOW TABLE STATUS');
            foreach ($tables as $table) {
                $fields = Db::getFields($table['Name']);
                foreach ($fields as $key => $val) {
                    // 移除用户自定义注释内容
                    $val['comment'] = preg_replace("/(?=【).*?(?<=】)/", '', $val['comment']);
                    $data[$table['Name']][$key] = $val['comment'] ?: $key;
                }
            }
            $this->app->cache->set('Tables', $data);
        }
        return $tableName ? ($data[StrExtend::humpToUnderline($tableName)] ?? []) : $data;
    }
}
