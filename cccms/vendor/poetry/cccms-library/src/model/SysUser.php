<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\services\UserService;
use cccms\extend\{ArrExtend, JwtExtend};
use think\model\relation\HasOne;

class SysUser extends Model
{
    /**
     * 用户详细信息
     */
    public function info(): HasOne
    {
        return $this->hasOne(SysUserInfo::class, 'user_id', 'id');
    }

    /**
     * 获取用户权限菜单
     * @param array $userInfo
     * @return array
     */
    public function getUserMenus(array $userInfo = []): array
    {
        $menus = SysMenu::mk()->where('status', 1)->cache('86400')
            ->column('id,parent_id,menu_id,name,node,icon,sort,url,status', 'id');
        foreach ($menus as $mKey => &$mVal) {
            if (isset($menus[$mVal['menu_id']]) && $mVal['menu_id'] !== 0) $menus[$mVal['menu_id']]['url'] = '#';
            if (!empty($mVal['node']) && $mVal['node'] !== '#' && !in_array($mVal['node'], $userInfo['nodes'])) unset($menus[$mKey]);
        }
        $pMenuId = array_column($menus, 'menu_id');
        foreach ($menus as $k => $v) {
            // 判断父级菜单是否存在子级菜单
            if ($v['url'] === '#' && !in_array($v['id'], $pMenuId)) unset($menus[$k]);
        }
        $menus = ArrExtend::toSort($menus, 'sort') ?? [];
        return ArrExtend::toTreeArray($menus, 'id', 'menu_id');
    }

    /**
     * 获取用户权限菜单
     * @param $value
     * @param $data
     * @return array
     */
    public function getMenusAttr($value, $data): array
    {
        return $this->getUserMenus($data);
    }

    /**
     * 获取用户权限节点
     * @param $value
     * @param $data
     * @return array
     */
    public function getNodesAttr($value, $data): array
    {
        $data['nodes'] = UserService::instance()->getUserNodes($data);
        $this->data($data, true);
        return $data['nodes'];
    }

    /**
     * 获取AccessToken
     * @param $value
     * @param $data
     * @return string
     */
    public function getAccessTokenAttr($value, $data): string
    {
        $data['is_admin'] = $data['id'] === 1;
        $data['login_expire'] = time() + config('session.expire', 86400);
        $this->data($data, true);
        return JwtExtend::getToken($data);
    }

    /**
     * 获取密码
     * @return string
     */
    public function getPassWordAttr(): string
    {
        return '******';
    }

    /**
     * 设置密码
     * @param $value
     * @param $data
     * @return string|SysUser
     */
    public function setPassWordAttr($value, $data): string|SysUser
    {
        if (empty($value)) {
            unset($data['password']);
            return $this->data($data, true);
        }
        return md5($value);
    }

    /**
     * 随机Token
     * @return string
     */
    public function setTokenAttr(): string
    {
        return md5(mt_rand(0, time()) . time());
    }

    /**
     * 设置账号状态
     * @param $value
     * @param $data
     * @return mixed
     */
    public function setStatusAttr($value, $data): mixed
    {
        if ($data['id'] == 1) $value = 1;
        if ($data['id'] == UserService::instance()->getUserInfo('id')) {
            _result(['code' => 403, 'msg' => '不能冻结自己的账户'], _getEnCode());
        }
        return $value;
    }

    public function searchUserAttr($query, $value): void
    {
        $query->where('nickname|username', 'like', '%' . $value . '%');
    }

    public function searchDeptIdAttr($query, $value): void
    {
        $query->when($value, function ($query) use ($value) {
            $query->where('id', 'in', function ($query) use ($value) {
                $query->table('sys_auth')->where('dept_id', '=', $value)->field('user_id');
            });
        });
    }
}
