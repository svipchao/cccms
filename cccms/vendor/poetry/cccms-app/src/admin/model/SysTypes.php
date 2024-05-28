<?php
declare(strict_types=1);

namespace app\admin\model;

use think\facade\Cache;
use cccms\Model;
use think\model\relation\HasMany;

class SysTypes extends Model
{
    protected $append = ['type_text'];

    public static function onBeforeWrite($model)
    {
        Cache::delete('SysTypes');
    }

    // 删除前
    public static function onBeforeDelete($model)
    {
        $data = $model->withCount(['menus', 'configs', 'routes', 'files'])->_read($model['id'], function ($data) {
            $data = $data->toArray();
            $data['data_count'] = $data['menus_count'] + $data['configs_count'] + $data['routes_count'] + $data['files_count'];
            return $data;
        });
        if ($data['data_count'] > 0) {
            _result(['code' => 403, 'msg' => '类别下存在数据，禁止删除'], _getEnCode());
        }
    }

    public function menus(): HasMany
    {
        return $this->hasMany('SysMenu', 'type_id', 'id');
    }

    public function configs(): HasMany
    {
        return $this->hasMany('SysConfig', 'type_id', 'id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany('SysRoute', 'type_id', 'id');
    }

    public function files(): HasMany
    {
        return $this->hasMany('SysFile', 'type_id', 'id');
    }

    public function searchTypeAttr($query, $value)
    {
        $query->where('type', $value);
    }

    public function getTypeTextAttr($value, $data): string
    {
        return config('cccms.types.type')[$data['type']] ?? '未知';
    }
}