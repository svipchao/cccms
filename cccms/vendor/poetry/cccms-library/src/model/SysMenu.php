<?php

declare(strict_types=1);

namespace cccms\model;

use think\facade\Cache;
use cccms\Model;
use cccms\services\{MenuService, UserService};

class SysMenu extends Model
{
    public static function onBeforeWrite($model): void
    {
        Cache::delete('SysMenus');
    }

    // 删除前
    public static function onBeforeDelete($model): void
    {
        if (count(MenuService::instance()->isMenuChildren((int)$model['id'])) > 1) {
            _result(['code' => 403, 'msg' => '存在子级菜单，禁止删除'], _getEnCode());
        }
    }

    public function setMenuIdAttr($value, $data): int
    {
        if (empty($value) && UserService::instance()->isAdmin()) return 0;
        if (isset($data['id'])) {
            if (in_array($value, MenuService::instance()->isMenuChildren((int)$data['id']))) {
                _result(['code' => 403, 'msg' => '不能选择自己的子菜单'], _getEnCode());
            }
        }
        return (int)$value;
    }

    public function searchParentIdAttr($query, $value)
    {
        $query->where('parent_id', '=', $value);
    }
}
