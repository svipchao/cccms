<?php
declare(strict_types=1);

namespace app\admin\model;

use think\facade\Cache;
use think\model\relation\BelongsToMany;
use cccms\Model;
use cccms\services\AuthService;

class SysRole extends Model
{
    // 写入后
    public static function onAfterWrite($model)
    {
        Cache::delete('SysRoles');
    }

    // 删除前
    public static function onBeforeDelete($model)
    {
        if (!in_array($model['id'], AuthService::instance()->getUserRoles(true))) {
            _result(['code' => 403, 'msg' => '未拥有该角色'], _getEnCode());
        }
        if (!empty(AuthService::instance()->getRoleChildren((int)$model['id'], false))) {
            _result(['code' => 403, 'msg' => '存在子级角色，禁止删除'], _getEnCode());
        }
    }

    public function getAllRoles(): array
    {
        return $this->field('id,role_id,role_name,role_desc')->_list();
    }

    public function groups(): belongsToMany
    {
        return $this->belongsToMany(SysGroup::class, SysAuth::class, 'group_id', 'role_id');
    }

    public function getNodesAttr($value): array
    {
        return explode(',', $value);
    }

    public function setNodesAttr($value): string
    {
        return implode(',', $value);
    }

    public function setRoleIdAttr($value, $data): int
    {
        if (empty($value) && AuthService::instance()->isAdmin()) return 0;
        if (!in_array($value, AuthService::instance()->getUserRoles(true))) {
            _result(['code' => 403, 'msg' => '未拥有该角色'], _getEnCode());
        }
        if (isset($data['id'])) {
            if (in_array($value, AuthService::instance()->getRoleChildren((int)$data['id'], false))) {
                _result(['code' => 403, 'msg' => '不能选择自己的子角色'], _getEnCode());
            }
        }
        return (int)$value;
    }
}