<?php
declare(strict_types=1);

namespace cccms;

use cccms\extend\StrExtend;
use think\db\exception\DbException;

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
            $data = $this;
            if (isset($listRows['recycle'])) {
                if (is_string($listRows['recycle'])) {
                    $listRows['recycle'] = $listRows['recycle'] == 'true';
                }
                if ($listRows['recycle']) $data = $data->onlyTrashed();
            }
            if (is_string($data) || is_numeric($data)) {
                $data = $data->allowEmpty()->find($data);
            } elseif (is_array($data)) {
                $data = $data->where($data)->allowEmpty()->find();
            } else {
                $data = $data->allowEmpty()->find();
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
     * @param array|null $params
     * @param callable|null $callable 回调
     * @return mixed
     */
    public function _list(?array $params = null, ?callable $callable = null): mixed
    {
        try {
            $data = $this;
            if (isset($params['recycle'])) {
                if (is_string($params['recycle'])) {
                    $params['recycle'] = $params['recycle'] == 'true';
                }
                if ($params['recycle']) $data = $data->onlyTrashed();
            }
            $data = $data->select();
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
            $data = $this;
            if (isset($listRows['recycle'])) {
                if (is_string($listRows['recycle'])) {
                    $listRows['recycle'] = $listRows['recycle'] == 'true';
                }
                if ($listRows['recycle']) $data = $data->onlyTrashed();
            }
            $data = $data->paginate([
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
     * @param null $delete 删除类型
     * @param callable|null $callable 回调
     * @return bool
     */
    public function _delete(array|string $condition, $delete = null, ?callable $callable = null): bool
    {
        $data = $this;
        // 查询限制处理
        if (is_array($condition)) {
            $data = $data->where($condition);
        } else {
            $data = $data->whereIn('id', StrExtend::str2arr($condition));
        }
        // 阻止危险操作
        if (!$data->getOptions('where')) {
            _result(['code' => 403, 'msg' => '数据删除失败, 请稍候再试！'], _getEnCode());
        }
        try {
            if ($delete !== null) $data = $data->withTrashed();
            $data = $data->findOrEmpty();
            if ($data->isEmpty()) return false;
            if ($delete == 'delete') {
                // 如果开启软删除这是是真实删除
                $result = $data->force()->delete();
            } elseif ($delete == 'restore') {
                // 如果开启软删除这里是恢复数据
                $result = $data->restore();
            } else {
                // 如果开启软删除这里就是软删除
                $result = $data->delete();
            }
            if (is_callable($callable)) {
                return call_user_func($callable, $result);
            }
            return $result;
        } catch (DbException $e) {
            $message = app()->isDebug() ? $e->getMessage() : '数据删除失败, 请稍候再试！';
            _result(['code' => 403, 'msg' => $message], _getEnCode());
        }
    }
}