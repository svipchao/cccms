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
        if (!empty(MenuService::mk()->getMenuChildren((int)$model['id'], false))) {
            _result(['code' => 403, 'msg' => '存在子级菜单，禁止删除'], _getEnCode());
        }
    }

    public function setMenuIdAttr($value, $data): int
    {
        if (empty($value) && UserService::mk()->isAdmin()) return 0;
        if (isset($data['id'])) {
            if (in_array($value, MenuService::mk()->getMenuChildren((int)$data['id'], false))) {
                _result(['code' => 403, 'msg' => '不能选择自己的子菜单'], _getEnCode());
            }
        }
        return (int)$value;
    }
}