<?php
declare(strict_types=1);

namespace app\admin\model;

use think\facade\Cache;
use think\model\relation\HasOne;
use cccms\Model;
use cccms\services\{AuthService, TableService};

class SysData extends Model
{
    protected $hidden = ['role'];

    public static function onBeforeWrite($model)
    {
        Cache::delete('SysData');
    }

    // 删除前
    public static function onBeforeDelete($model)
    {
        // 判断是否拥有该角色权限
        if (!in_array($model['role_id'], AuthService::instance()->getUserRoles(true))) {
            _result(['code' => 403, 'msg' => '未拥有该角色'], _getEnCode());
        }
    }

    public function role(): HasOne
    {
        return $this->hasOne(SysRole::class, 'id', 'role_id')
            ->bind(['role_name']);
    }

    // 角色ID搜索器
    public function searchRoleIdAttr($query, $value)
    {
        $value = $value ?: implode(',', AuthService::instance()->getUserRoles(true));
        $query->where('role_id', 'in', $value);
    }

    // 表名搜索器
    public function searchTableAttr($query, $value)
    {
        $query->where('table', '=', $value);
    }

    public function setRoleIdAttr($value)
    {
        // 判断角色权限
        if (!in_array($value, AuthService::instance()->getUserRoles(true))) {
            _result(['code' => 403, 'msg' => '未拥有该角色'], _getEnCode());
        }
        return $value;
    }

    public function setTableAttr($value, $data)
    {
        // 判断字段权限
        // PS:这里只判断到字段权限，如果数据权限需要限制请自行开发
        $fields = TableService::instance()->fields($value);
        if (!isset($fields[$data['field']])) {
            _result(['code' => 403, 'msg' => '字段权限不存在'], _getEnCode());
        }
        return $value;
    }
}
