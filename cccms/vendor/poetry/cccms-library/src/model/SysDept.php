<?php

declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\services\UserService;
use think\facade\Db;
use think\db\exception\DbException;
use think\model\concern\SoftDelete;
use think\model\relation\{HasMany, BelongsToMany};

class SysDept extends Model
{
    use SoftDelete;

    protected string $deleteTime = 'delete_time';

    protected $defaultSoftDelete = '1900-01-01 00:00:00';

    // 写入后
    public static function onAfterWrite($model): void
    {
        parent::onAfterWrite($model);
        $data = $model->toArray();
        if (!empty($data['role'])) {
            SysDeptRole::mk()->where('dept_id', $data['id'])->delete();
            $deptRoleData = [];
            foreach ($data['role'] as $role) {
                $deptRoleData[$role['id']] = [
                    'dept_id' => $data['id'],
                    'role_id' => $role['id'],
                ];
            }
            if (!empty($deptRoleData)) SysDeptRole::mk()->saveAll($deptRoleData);
        }
        if (isset($data['dept_id'])) {
            $parent = $model->where('id', $data['dept_id'] ?: 0)->value('dept_path');
            $parent = (empty($parent) ? ',' : $parent) . $data['id'] . ',';
            Db::table('sys_dept')->where('id', $data['id'])->update(['dept_path' => $parent]);
        }
    }

    /**
     * 删除前
     * @param $model
     * @return void
     * @throws DbException
     */
    public static function onBeforeDelete($model): void
    {
        parent::onBeforeDelete($model);
        $data = $model->toArray();
        $sonCount = $model->whereFindInSet('dept_id', $data['id'])->count();
        if (!empty($sonCount)) {
            _result(['code' => 403, 'msg' => '存在子级部门 禁止删除'], _getEnCode());
        }
        // 删除所有关联权限数据
        if ($model->isForce()) {
            SysDeptRole::mk()->where('dept_id', $data['id'])->delete();
        }
    }

    public function role(): BelongsToMany
    {
        return $this->belongsToMany(SysRole::class, SysDeptRole::class, 'role_id', 'dept_id');
    }

    public function userDeptRelation(): HasMany
    {
        return $this->hasMany(SysUserDept::class, 'dept_id', 'id');
    }

    public function setDeptIdAttr($value, $data): int
    {
        return $value ?: 0;
    }

    public function getAllOpenDept(): array
    {
        return $this->where('status', 1)->_list();
    }

    public function getAllOpenDeptIds(): array
    {
        return array_column($this->getAllOpenDept(), 'id');
    }

    public function getUserDept(int $userId = 0): array
    {
        if (UserService::isAdmin()) return $this->where('status', 1)->_list();
        $userId = $userId ?: UserService::getUserId();
        return $this->hasWhere('userDeptRelation', function ($query) use ($userId) {
            $query->where('user_id', '=', $userId);
        })->where('status', 1)->_list();
    }

    public function getUserDeptAll(int $userId = 0): array
    {
        if (UserService::isAdmin()) return $this->_list();
        $userId = $userId ?: UserService::getUserId();
        return $this->hasWhere('userDeptRelation', function ($query) use ($userId) {
            $query->where('user_id', '=', $userId);
        })->_list();
    }
}
