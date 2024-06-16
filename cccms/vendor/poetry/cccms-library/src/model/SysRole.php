<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;
use cccms\services\UserService;
use think\facade\Db;
use think\model\relation\HasMany;
use think\model\concern\SoftDelete;
use think\db\exception\DbException;

class SysRole extends Model
{
    use SoftDelete;

    protected string $deleteTime = 'delete_time';

    protected $defaultSoftDelete = '1900-01-01 00:00:00';

    protected $hidden = ['pivot'];

    // 写入后
    public static function onAfterWrite($model): void
    {
        parent::onAfterWrite($model);
        $data = $model->toArray();
        if (!empty($data['nodes'])) {
            SysRoleNode::mk()->where('role_id', $data['id'])->delete();
            $roleNodeData = [];
            if (is_string($data['nodes'])) $data['nodes'] = explode(',', $data['nodes']);
            foreach ($data['nodes'] as $node) {
                $roleNodeData[$node] = [
                    'role_id' => $data['id'],
                    'node' => $node,
                ];
            }
            if (!empty($roleNodeData)) SysRoleNode::mk()->saveAll($roleNodeData);
        }
        if (isset($data['role_id'])) {
            $parent = $model->where('id', $data['role_id'] ?: 0)->value('role_path');
            $parent = (empty($parent) ? ',' : $parent) . $data['id'] . ',';
            Db::table('sys_role')->where('id', $data['id'])->update(['role_path' => $parent]);
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
        $sonCount = $model->whereFindInSet('role_id', $data['id'])->count();
        if (!empty($sonCount)) {
            _result(['code' => 403, 'msg' => '存在子级角色 禁止删除'], _getEnCode());
        }
        // 删除所有关联权限数据
        if ($model->isForce()) {
            SysRoleNode::mk()->where('role_id', $data['id'])->delete();
        }
    }

    public function nodesRelation(): HasMany
    {
        return $this->hasMany(SysRoleNode::class, 'role_id', 'id');
    }

    public function setRoleIdAttr($value, $data): int
    {
        return $value ?: 0;
    }

    public function getAllOpenRole(): array
    {
        return $this->where('status', 1)->_list();
    }

    public function getAllOpenRoleIds(): array
    {
        return array_column($this->getAllOpenRole(), 'id');
    }

    public function getUserRole(int $user_id = 0): array
    {
        if (UserService::isAdmin()) return $this->where('status', 1)->_list();
        $user_id = $user_id ?: UserService::getUserId();
        return $this->where('id', 'in', function ($query) use ($user_id) {
            $userDeptIds = UserService::instance()->getUserDeptIds($user_id);
            return $query->table('sys_dept_role')->field('role_id')->where('dept_id', 'in', $userDeptIds);
        })->where('status', 1)->_list();
    }

    public function getUserRoleAll(int $user_id = 0): array
    {
        if (UserService::isAdmin()) return $this->_list();
        $user_id = $user_id ?: UserService::getUserId();
        return $this->where('id', 'in', function ($query) use ($user_id) {
            $userDeptIds = UserService::instance()->getUserDeptIds($user_id);
            return $query->table('sys_dept_role')->field('role_id')->where('dept_id', 'in', $userDeptIds);
        })->_list();
    }
}
