<?php
declare(strict_types=1);

namespace cccms;

use cccms\services\{NodeService, UserService, DataService};

/**
 * @method Query _withSearch(string|array $fields, mixed|array $data = [], string $prefix = '', $value = null) 搜索器
 * @method mixed _column(string|array $field, string $key = '') 查找数据
 * @method mixed _read(mixed $data = null, ?callable $callable = null) 查找数据
 * @method array _list(?array $where = null, ?callable $callable = null) 数组
 * @method array _page(?array $listRows = null, bool|int $simple = false, ?callable $callable = null) 分页查询
 * @method bool _delete(array|string $condition, ?callable $callable = null) 快捷删除
 */
abstract class Model extends \think\Model
{
    protected $globalScope = ['commonAuth'];

    /**
     * 创建模型实例
     * @return static
     */
    public static function mk($data = []): Model
    {
        return new static($data);
    }

    /**
     * 查询后
     * @param $model
     */
    public static function onAfterRead($model)
    {
    }

    /**
     * 新增前
     * @param $model
     */
    public static function onBeforeInsert($model)
    {
    }

    /**
     * 新增后
     * @param $model
     */
    public static function onAfterInsert($model)
    {
    }

    /**
     * 更新前
     * @param $model
     */
    public static function onBeforeUpdate($model)
    {
    }

    /**
     * 更新后
     * @param $model
     */
    public static function onAfterUpdate($model)
    {
    }

    /**
     * 写入前
     * @param $model
     */
    public static function onBeforeWrite($model)
    {
        // $data = DataService::instance()->getUserData($model->name);
        // // 如果主键不存在 则不让更新 或 写入 没有写入权限
        // if (in_array($model->pk, $data['readOnly'])) return false;
        // $readOnly = array_diff_key($model->getData(), array_flip($data['readOnly']));
        // $model->data($readOnly, true);
    }

    /**
     * 写入后
     * @param $model
     */
    public static function onAfterWrite($model)
    {
    }

    /**
     * 删除前
     * @param $model
     */
    public static function onBeforeDelete($model)
    {
        // $data = DataService::instance()->getUserData($model->name);
        // // 如果字段主键只读 数据禁止删除
        // if (in_array($model->pk, $data['readOnly'])) return false;
    }

    /**
     * 删除后
     * @param $model
     */
    public static function onAfterDelete($model)
    {
    }

    /**
     * 恢复前
     * @param $model
     */
    public static function onBeforeRestore($model)
    {
    }

    /**
     * 恢复后
     * @param $model
     */
    public static function onAfterRestore($model)
    {
    }

    public function scopeCommonAuth($query): void
    {
        $node = NodeService::instance()->getCurrentNodeInfo();
        if (!empty($node) && $node['auth'] && !UserService::instance()->isAdmin()) {
            $fields = $query->getTableFields();
            // 数据权限
            // $this->commonDataAuth($query, $fields);
            // 用户数据范围
            $this->commonUserAuth($query, $fields);
            // 部门数据范围
            // $this->commonDeptAuth($query, $fields);
            // 角色数据范围
            // $this->commonRoleAuth($query, $fields);
        }
    }

    // 数据权限
    private function commonDataAuth($query, $fields): void
    {
        $data = DataService::instance()->getUserData($this->name);
        $data['fields'] = array_intersect($fields, $data['fields']);
        $data['withoutField'] = array_intersect($fields, $data['withoutField']);
        // 查询字段
        if (!empty($data['field'])) $query->field(array_intersect($fields, $data['fields']));
        // 排除字段
        if (!empty($data['withoutField'])) $query->withoutField($data['withoutField']);
        // 并且条件
        if (!empty($data['whereAndMap'])) $query->where($data['whereAndMap']);
        // 或者条件
        if (!empty($data['whereOrMap'])) {
            $query->where(function ($query) use ($data) {
                $query->whereOr($data['whereOrMap']);
            });
        }
        // 掩码显示
        if (!empty($data['maskShow'])) {
            foreach ($data['maskShow'] as $mask) {
                $query->withAttr($mask, function () {
                    return '***********';
                });
            }
        }
    }

    // 用户数据范围
    private function commonUserAuth($query, $fields): void
    {
        if (in_array('user_id', $fields)) {
            $query->where('user_id', 'in', function ($query) {
                $query->table('sys_auth')->whereOr([
                    ['dept_id', 'in', UserService::instance()->getUserDeptIds()],
                    ['user_id', 'in', UserService::instance()->getUserSubUserIds()]
                ])->field('user_id');
            });
        }
    }

    // 部门数据范围
    private function commonDeptAuth($query, $fields): void
    {
        if (in_array('dept_id', $fields)) {
            $query->where('dept_id', 'in', UserService::instance()->getUserDeptIds());
        }
    }

    // 角色数据范围
    private function commonRoleAuth($query, $fields): void
    {
        if (in_array('role_id', $fields)) {
            $query->where('role_id', 'in', UserService::instance()->getUserRoleIds());
        }
    }
}
