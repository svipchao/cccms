<?php

declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\extend\ArrExtend;
use cccms\services\DataService;
use think\db\exception\DbException;
use think\model\relation\{HasMany, BelongsToMany};

class SysDept extends Model
{
    /**
     * 新增后
     * @param $model
     */
    public static function onAfterInsert($model)
    {
        halt($model);
    }

    public function setDeptIdAttr($value, $data)
    {
        $data['dept_ids'] = $value ? $this->where('id', $value)->value('dept_ids') . ',' : '';
        if (isset($data['id'])) {
            // 更新
            $sonRes = $this->whereOr([
                ['id', '=', $data['id']],
                ['dept_id', 'find in set', $data['id']]
            ])->column('id,dept_ids');
            if ($value !== 0 && in_array($value, array_column($sonRes, 'id'))) {
                _result(['code' => 403, 'msg' => '不能选择自己的子部门'], _getEnCode());
            }
            // 记录path
            foreach ($sonRes as &$son) {
                $son['dept_ids'] = $data['dept_ids'] . strstr($son['dept_ids'], (string)$data['id']);
            }
            $this->saveAll($sonRes);
        } else {
            // 新增 修复选择上级部门500报错
            // $this->data($data, true);
        }
        return $value;
    }

    public function setDeptIdsAttr($value, $data)
    {
        halt($data);
    }

    // 写入后
    public static function onAfterWrite($model)
    {
        if (isset($model['role_ids']) && !empty($model['role_ids'])) {
            if (is_string($model['role_ids'])) {
                $model['role_ids'] = explode(',', $model['role_ids']);
            }
            $roles = $model->roles()->_list();
            if (!empty($roles)) {
                $model->roles()->detach(array_column($roles, 'id'));
            }
            $model->roles()->attach($model['role_ids']);
        }
        if (isset($model['nodes']) && !empty($model['nodes'])) {
            if (is_string($model['nodes'])) {
                $model['nodes'] = explode(',', $model['nodes']);
            }
            $model->nodesRelation()->delete();
            $model->nodesRelation()->saveAll(ArrExtend::createTwoArray($model['nodes'], 'node'));
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
        $sonCount = $model->whereFindInSet('dept_id', $model['id'])->count();
        if (!empty($sonCount)) {
            _result(['code' => 403, 'msg' => '存在子级部门，禁止删除'], _getEnCode());
        }
        // 删除所有关联权限数据
        $model->auth()->delete();
    }

    public function auth(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'dept_id', 'id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(SysRole::class, SysAuth::class, 'role_id', 'dept_id');
    }

    public function nodesRelation(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'dept_id', 'id')->where([
            ['dept_id', '<>', 0],
            ['node', '<>', ''],
        ]);
    }

    /**
     * 获取组织状态开启列表
     * @param $isTree
     * @return array
     */
    public function getAllOpenDept($isTree = false): array
    {
        if ($isTree) {
            // 移除部门缓存
            // $data = cache('allDeptOpenTree');
            // if (empty($data)) {
            //     $data = $this->where('status', 1)->_list();
            //     $data = ArrExtend::toTreeArray($data, 'id', 'dept_id');
            //     cache('allDeptOpenTree', $data);
            // }
            $data = $this->where('status', 1)->_list();
            $data = ArrExtend::toTreeArray($data, 'id', 'dept_id');
        } else {
            // 移除部门缓存
            // $data = cache('allDeptOpenList');
            // if (empty($data)) {
            //     $data = $this->where('status', 1)->_list();
            //     $data = ArrExtend::toTreeList($data, 'id', 'dept_id');
            //     cache('allDeptOpenList', $data);
            // }
            $data = $this->where('status', 1)->_list();
            $data = ArrExtend::toTreeList($data, 'id', 'dept_id');
        }
        return $data;
    }

    public function getAllOpenDeptIds(): array
    {
        return array_column($this->getAllOpenDept(), 'id');
    }
}
