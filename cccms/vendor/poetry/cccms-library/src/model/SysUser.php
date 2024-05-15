<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\extend\{ArrExtend, JwtExtend};
use cccms\services\{NodeService, UserService, ConfigService};
use think\model\relation\{BelongsToMany, HasMany, HasOne, belongsTo};

class SysUser extends Model
{
    // 写入后
    public static function onAfterWrite($model)
    {
        if (isset($model['role_ids']) && !empty($model['role_ids'])) {
            if (is_string($model['role_ids'])) {
                $model['role_ids'] = explode(',', $model['role_ids']);
            }
            // 删除组织关联权限节点表数据
            $model->roles()->detach($model->roles()->column('id'));
            $model->roles()->attach($model['role_ids']);
        }
        if (isset($model['dept_ids']) && !empty($model['dept_ids'])) {
            if (is_string($model['dept_ids'])) {
                $model['dept_ids'] = explode(',', $model['dept_ids']);
            }
            // 删除组织关联权限节点表数据
            $model->depts()->detach($model->depts()->column('id'));
            $model->depts()->attach($model['dept_ids']);
        }
    }

    /**
     * 邀请信息
     */
    public function invite(): HasOne
    {
        return $this->HasOne(SysUser::class, 'id', 'invite_id')->bind([
            'invite_nickname' => 'nickname'
        ]);
    }

    public function auth(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'user_id', 'id');
    }

    public function depts(): BelongsToMany
    {
        return $this->belongsToMany(SysDept::class, SysAuth::class, 'dept_id', 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(SysRole::class, SysAuth::class, 'role_id', 'user_id');
    }

    /**
     * 获取用户权限菜单
     * @param array $userInfo
     * @return array
     */
    public function getUserMenus(array $userInfo = []): array
    {
        $menus = SysMenu::mk()->where('status', 1)->cache('86400')
            ->column('id,parent_id,menu_id,name,node,icon,layout_name,target,sort,url,status', 'id');
        $authNodes = array_keys(NodeService::instance()->getAuthNodes());
        foreach ($menus as $mKey => &$mVal) {
            if (isset($menus[$mVal['menu_id']]) && $mVal['menu_id'] !== 0) {
                $menus[$mVal['menu_id']]['url'] = '#';
            }
            $mVal['auth'] = true;
            if (!in_array($mVal['node'], $authNodes)) {
                $mVal['auth'] = false;
            } else {
                if (!empty($mVal['node']) && $mVal['node'] !== '#' && !in_array($mVal['node'], $userInfo['nodes'])) {
                    unset($menus[$mKey]);
                }
            }
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
     * 获取配置项
     * @param $value
     * @param $data
     * @return string
     */
    public function getConfigsAttr($value, $data): array
    {
        return ConfigService::instance()->getConfig();
    }

    public function getPassWordAttr(): string
    {
        return '';
    }

    public function getPhoneAttr($value): int
    {
        return (int)$value;
    }

    public function getTagsAttr($value): array
    {
        return $value ? explode(',', $value) : [];
    }

    public function setTagsAttr($value): string
    {
        if (is_string($value)) return $value;
        return $value ? implode(',', $value) : '';
    }

    /**
     * 设置密码
     * @param $value
     * @param $data
     */
    // public function setPassWordAttr($value, $data)
    // {
    //     if (empty($value)) {
    //         unset($data['password']);
    //         $this->data($data, true);
    //     } else {
    //         return md5($value);
    //     }
    // }

    /**
     * 设置账号状态
     * @param $value
     * @param $data
     * @return mixed
     */
    public function setStatusAttr($value, $data): mixed
    {
        // 禁止管理员修改自己状态
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

    public function searchTagAttr($query, $value): void
    {
        $query->when($value, function ($query) use ($value) {
            $query->where('tags', 'like', '%' . $value . '%');
        });
    }

    public function searchTypeAttr($query, $value): void
    {
        $query->where('type', '=', $value);
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
