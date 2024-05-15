<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\extend\ArrExtend;
use think\model\relation\HasMany;
use think\db\exception\DbException;

class SysRole extends Model
{
    public function setRoleIdAttr($value, $data)
    {
        $sonRes = $this->whereOr([
            ['id', '=', $data['id']],
            ['role_id', 'find in set', $data['id']]
        ])->column('id,role_ids');
        if ($value !== 0 && in_array($value, array_column($sonRes, 'id'))) {
            _result(['code' => 403, 'msg' => '不能选择自己的子角色'], _getEnCode());
        }
        // 记录path
        $parentPath = $value ? $this->where('id', $value)->value('role_ids') . ',' : '';
        foreach ($sonRes as &$son) {
            $son['role_ids'] = $parentPath . strstr($son['role_ids'], (string)$data['id']);
        }
        $this->saveAll($sonRes);
        return $value;
    }

    public function setNodesAttr($value, $data)
    {
        // 这里需要判断子级角色的节点交集-待实现
        if (is_string($value)) $value = explode(',', $value);
        if (!is_array($value)) return true;
        // 只处理节点权限
        $this->nodesRelation()->delete();
        $this->nodesRelation()->saveAll(ArrExtend::createTwoArray($value, 'node'));
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
        $sonCount = $model->whereFindInSet('role_id', $model['id'])->count();
        if (!empty($sonCount)) {
            _result(['code' => 403, 'msg' => '存在子级角色，禁止删除'], _getEnCode());
        }
        // 删除所有关联权限数据
        $model->auth()->delete();
    }

    public function auth(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'role_id', 'id');
    }

    public function nodesRelation(): HasMany
    {
        return $this->hasMany(SysAuth::class, 'role_id', 'id')->where([
            ['role_id', '<>', 0],
            ['node', '<>', ''],
        ]);
    }

    /**
     * 获取角色状态开启列表
     * @param $isTree
     * @return array
     */
    public function getAllOpenRole($isTree = false): array
    {
        if ($isTree) {
            // $data = cache('allRoleOpenTree');
            // if (empty($data)) {
            //     $data = $this->where('status', 1)->_list();
            //     $data = ArrExtend::toTreeArray($data, 'id', 'role_id');
            //     cache('allRoleOpenTree', $data);
            // }
            $data = $this->where('status', 1)->_list();
            $data = ArrExtend::toTreeArray($data, 'id', 'role_id');
        } else {
            // $data = cache('allRoleOpenList');
            // if (empty($data)) {
            //     $data = $this->where('status', 1)->_list();
            //     $data = ArrExtend::toTreeList($data, 'id', 'role_id');
            //     cache('allRoleOpenList', $data);
            // }
            $data = $this->where('status', 1)->_list();
            $data = ArrExtend::toTreeList($data, 'id', 'role_id');
        }
        return $data;
    }

    public function getAllOpenRoleIds(): array
    {
        return array_column($this->getAllOpenRole(), 'id');
    }
}
