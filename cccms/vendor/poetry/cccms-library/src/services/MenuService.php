<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\Service;
use cccms\model\SysMenu;
use cccms\extend\ArrExtend;

class MenuService extends Service
{
    public function getAllMenus(): array
    {
        return SysMenu::mk()->withoutField('create_time,update_time')->cache(600)->_list();
    }

    /**
     * 获取菜单子集菜单
     * @param int $menu_id 菜单ID
     * @param bool $isId 是否返回ID
     * @return array
     */
    public function getMenuChildren(int $menu_id = 0, bool $isId = true): array
    {
        $menu = ArrExtend::toChildren($this->getAllMenus(), $menu_id, 'id', 'menu_id');
        return $isId ? array_column($menu, 'id') : $menu;
    }
}
