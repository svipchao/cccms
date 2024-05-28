<?php
declare(strict_types=1);

namespace app\admin\model;

use think\facade\Cache;
use think\model\relation\belongsToMany;
use cccms\Model;
use cccms\services\AuthService;

class SysGroup extends Model
{
    // 写入后
    public static function onAfterWrite($model)
    {
        Cache::delete('SysGroups');
    }

    // 删除前
    public static function onBeforeDelete($model)
    {
        if (!in_array($model['id'], AuthService::instance()->getUserGroups(true))) {
            _result(['code' => 403, 'msg' => '未拥有该组织'], _getEnCode());
        }
        if (!empty(AuthService::instance()->getGroupChildren((int)$model['id'], false))) {
            _result(['code' => 403, 'msg' => '存在子级组织，禁止删除'], _getEnCode());
        }
    }

    // 删除后
    public static function onAfterDelete($model)
    {
        $model->users()->detach($model->users()->column('id'));
    }

    public function getAllGroups(): array
    {
        return $this->field('id,group_id,group_name,group_desc')->_list();
    }

    // 关联用户
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(SysUser::class, SysAuth::class, 'user_id', 'group_id');
    }

    public function setGroupIdAttr($value, $data): int
    {
        if (empty($value) && AuthService::instance()->isAdmin()) return 0;
        if (!in_array($value, AuthService::instance()->getUserGroups(true))) {
            _result(['code' => 403, 'msg' => '未拥有该组织'], _getEnCode());
        }
        if (isset($data['id'])) {
            if (in_array($value, AuthService::instance()->getGroupChildren((int)$data['id'], false))) {
                _result(['code' => 403, 'msg' => '不能选择自己的子组织'], _getEnCode());
            }
        }
        return (int)$value;
    }
}