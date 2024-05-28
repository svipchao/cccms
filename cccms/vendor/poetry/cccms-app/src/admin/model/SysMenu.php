<?php
declare(strict_types=1);

namespace app\admin\model;

use think\facade\Cache;
use think\model\relation\HasOne;
use cccms\Model;
use cccms\services\{AuthService, TypesService, MenuService};

class SysMenu extends Model
{
    public static function onBeforeWrite($model)
    {
        Cache::delete('SysMenus');
    }

    // 删除前
    public static function onBeforeDelete($model)
    {
        if (!empty(MenuService::instance()->getMenuChildren((int)$model['id'], false))) {
            _result(['code' => 403, 'msg' => '存在子级菜单，禁止删除'], _getEnCode());
        }
    }

    public function type(): HasOne
    {
        return $this->hasOne(SysTypes::class, 'id', 'type_id')
            ->where('type', 1)->bind([
                'type_name' => 'name'
            ]);
    }

    // 类别搜索器
    public function searchTypeIdAttr($query, $value)
    {
        $types = TypesService::instance()->getTypes(1, 'id');
        if (empty($types)) {
            $value = 0;
        } elseif (!isset($types[$value])) {
            $value = array_shift($types)['id'] ?? 0;
        }
        $query->where('type_id', '=', $value);
    }

    public function setTypeIdAttr($value): int
    {
        $types = TypesService::instance()->getTypes(1, 'id');
        if (!isset($types[$value])) {
            _result(['code' => 403, 'msg' => '类型错误'], _getEnCode());
        }
        return (int)$value;
    }

    public function setMenuIdAttr($value, $data): int
    {
        if (empty($value) && AuthService::instance()->isAdmin()) return 0;
        if (isset($data['id'])) {
            if (in_array($value, MenuService::instance()->getMenuChildren((int)$data['id'], false))) {
                _result(['code' => 403, 'msg' => '不能选择自己的子菜单'], _getEnCode());
            }
        }
        return (int)$value;
    }
}