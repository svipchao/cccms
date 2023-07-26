<?php
declare(strict_types=1);

namespace cccms;

use think\facade\Db;
use think\db\exception\DbException;
use cccms\extend\StrExtend;

class Query extends \think\db\Query
{
    /**
     * 递归查询
     * @param string|array $fields 搜索字段
     * @param mixed|array $data 搜索数据
     * @param string $prefix 字段前缀标识
     * @param null $value
     * @return mixed
     */
    public function _withSearch(array|string $fields, array $data = [], string $prefix = '', $value = null): Query
    {
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }
        foreach ($fields as $key => $field) {
            if (array_key_exists($field, $data) && $data[$field] === $value) {
                unset($fields[$key], $data[$field]);
            }
        }
        return parent::withSearch($fields, $data, $prefix);
    }

    /**
     * 查找数据
     * @param mixed|null $data
     * @param callable|null $callable 回调
     * @return mixed
     */
    public function _read(mixed $data = null, ?callable $callable = null): mixed
    {
        try {
            if (is_string($data) || is_numeric($data)) {
                $data = $this->allowEmpty()->find($data);
            } elseif (is_array($data)) {
                $data = $this->where($data)->allowEmpty()->find();
            } else {
                $data = $this->allowEmpty()->find();
            }
            if (is_callable($callable)) {
                $data = call_user_func($callable, $data);
            } else {
                $data = $data->toArray();
            }
            return $data;
        } catch (DbException $e) {
            $message = app()->isDebug() ? $e->getMessage() : '查询失败';
            _result(['code' => 403, 'msg' => $message], _getEnCode());
        }
    }

    /**
     * 数组
     * @param array|null $where
     * @param callable|null $callable 回调
     * @return mixed
     */
    public function _list(?array $where = null, ?callable $callable = null): mixed
    {
        try {
            $data = $this->where($where)->select();
            if (is_callable($callable)) {
                return call_user_func($callable, $data);
            } else {
                return $data->toArray();
            }
        } catch (DbException $e) {
            $message = app()->isDebug() ? $e->getMessage() : '查询失败';
            _result(['code' => 403, 'msg' => $message], _getEnCode());
        }
    }

    /**
     * 分页查询
     *    PS:withCache 与分页查询冲突 请不要一起使用
     * @param array|null $listRows 每页数量 数组表示配置参数
     * @param bool|int $simple 是否简洁模式或者总记录数
     * @param callable|null $callable 回调
     * @return mixed
     */
    public function _page(?array $listRows = null, bool|int $simple = false, ?callable $callable = null): mixed
    {
        try {
            $data = $this->paginate([
                'list_rows' => $listRows['limit'] ?? 15,
                'page' => $listRows['page'] ?? 1,
            ], $simple);
            if (is_callable($callable)) {
                return call_user_func($callable, $data);
            } else {
                return $data->toArray();
            }
        } catch (DbException $e) {
            $message = app()->isDebug() ? $e->getMessage() : '查询分页失败';
            _result(['code' => 403, 'msg' => $message], _getEnCode());
        }
    }

    /**
     * 快捷删除逻辑器
     * @param array|string $condition
     * @param callable|null $callable 回调
     * @return bool
     */
    public function _delete(array|string $condition, ?callable $callable = null): bool
    {
        // 查询限制处理
        if (is_array($condition)) {
            $query = $this->where($condition);
        } else {
            $query = $this->whereIn('id', StrExtend::str2arr($condition));
        }
        // 阻止危险操作
        if (!$query->getOptions('where')) {
            _result(['code' => 403, 'msg' => '数据删除失败, 请稍候再试！'], _getEnCode());
        }
        $query = $query->findOrEmpty();
        if ($query->isEmpty()) return false;
        // 组装执行数据
        $data = [];
        if (method_exists($query, 'getTableFields')) {
            $fields = $query->getTableFields();
            // 软删除
            if (in_array('delete_time', $fields)) $data['delete_time'] = time();
        }
        if (is_callable($callable)) {
            $query = call_user_func($callable, $query);
        }
        try {
            return (bool)(empty($data) ? $query->delete() : $query->update($data));
        } catch (DbException $e) {
            $message = app()->isDebug() ? $e->getMessage() : '数据删除失败, 请稍候再试！';
            _result(['code' => 403, 'msg' => $message], _getEnCode());
        }
    }
}