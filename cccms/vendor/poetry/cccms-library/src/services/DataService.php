<?php
declare(strict_types=1);

namespace cccms\services;

use cccms\Service;
use cccms\extend\StrExtend;

class DataService extends Service
{
    /**
     * 获取条件映射
     * @param string $where
     * @return array
     */
    public function getWhereCorresponding(string $where): array
    {
        return [
            'hidden' => ['name' => '隐藏', 'where' => '', 'format' => ''],
            'read_only' => ['name' => '只读', 'where' => '', 'format' => ''],
            'mask_show' => ['name' => '掩码显示', 'where' => '', 'format' => ''],
            'eq' => ['name' => '等于', 'where' => '=', 'format' => ''],
            'neq' => ['name' => '不等于', 'where' => '<>', 'format' => ''],
            'lt' => ['name' => '小于', 'where' => '<', 'format' => ''],
            'elt' => ['name' => '小于等于', 'where' => '<=', 'format' => ''],
            'gt' => ['name' => '大于', 'where' => '>', 'format' => ''],
            'egt' => ['name' => '大于等于', 'where' => '>=', 'format' => ''],
            'null' => ['name' => '为NULL', 'where' => 'null', 'format' => ''],
            'not_null' => ['name' => '为NULL(NOT)', 'where' => 'not null', 'format' => ''],
            'empty_string' => ['name' => '为空值', 'where' => '=', 'format' => ''],
            'not_empty_string' => ['name' => '为空值(NOT)', 'where' => '<>', 'format' => ''],
            'in' => ['name' => '在范围内', 'where' => 'in', 'format' => ''],
            'not_in' => ['name' => '在范围内(NOT)', 'where' => 'not in', 'format' => ''],
            'between' => ['name' => '在区间内', 'where' => 'between', 'format' => ''],
            'not_between' => ['name' => '在区间内(NOT)', 'where' => 'not between', 'format' => ''],
            'left_like' => ['name' => '左模糊', 'where' => 'like', 'format' => '%#[value]#'],
            'not_left_like' => ['name' => '左模糊(NOT)', 'where' => 'not like', 'format' => '%#[value]#'],
            'right_like' => ['name' => '右模糊', 'where' => 'like', 'format' => '#[value]#%'],
            'not_right_like' => ['name' => '右模糊(NOT)', 'where' => 'not like', 'format' => '#[value]#%'],
            'all_like' => ['name' => '全模糊', 'where' => 'like', 'format' => '%#[value]#%'],
            'not_all_like' => ['name' => '全模糊(NOT)', 'where' => 'not like', 'format' => '%#[value]#%'],
            'custom_like' => ['name' => '自定义模糊', 'where' => 'like', 'format' => '#[value]#'],
            'not_custom_like' => ['name' => '自定义模糊(NOT)', 'where' => 'not like', 'format' => '#[value]#'],
        ][$where] ?? [];
    }

    /**
     * 处理条件
     * @param string $field
     * @param string $where
     * @param string|int $value
     * @return array
     */
    public function handleWhere(string $field, string $where, string|int $value): array
    {
        if (empty($corresponding = $this->getWhereCorresponding($where))) return [];
        if (in_array($where, ['in', 'not_in', 'between', 'not_between'])) {
            $value = explode(',', $value);
        } elseif (str_contains($where, 'like')) {
            $value = str_replace('#[value]#', $value, $corresponding['format']);
        } elseif ($where == 'null') {
            return [$field, 'null'];
        } elseif ($where == 'not_null') {
            return [$field, 'not null'];
        } elseif (in_array($where, ['hidden', 'read_only', 'mask_show'])) {
            return [];
        }
        return [$field, $corresponding['where'], $value];
    }

    /**
     * 获取用户数据范围
     * @param string $table 表名
     * @return array
     */
    public function getUserData(string $table = ''): array
    {
        $tableName = StrExtend::humpToUnderline($table);
        $data = [
            'fields' => [], // 字段
            'withoutField' => [], // 排除字段
            'maskShow' => [], // 掩码显示
            'readOnly' => [], // 只读
            'whereAndMap' => [], // 并且条件
            'whereOrMap' => [], // 或者条件
        ];
        $userData = UserService::instance()->getUserAuths();
        foreach ($userData as $d) {
            if ($d['table_name'] !== $tableName) continue;
            if (empty($d['field'])) continue;
            // 掩码显示需要字段
            if ($d['where'] == 'mask_show') $data['maskShow'][$d['field']] = 0;
            // 只读也需要字段
            if ($d['where'] == 'read_only') $data['readOnly'][$d['field']] = 0;
            if ($d['where'] == 'hidden') {
                $data['withoutField'][$d['field']] = 0;
            } else {
                $data['fields'][$d['field']] = 0;
            }
            if (empty($d['where'])) continue;
            if (empty($where = $this->handleWhere($d['field'], $d['where'], $d['value']))) continue;
            $whereKey = md5($d['field'] . $d['where'] . $d['value']);
            if ($d['logical'] == 1) {
                $data['whereOrMap'][$whereKey] = $where;
            } elseif ($d['logical'] == 2) {
                $data['whereAndMap'][$whereKey] = $where;
            }
            // 访问控制 logical = 3 or 4 -待实现
        }
        return [
            'fields' => array_keys($data['fields']),
            'withoutField' => array_keys($data['withoutField']),
            'maskShow' => array_keys($data['maskShow']),
            'readOnly' => array_keys($data['readOnly']),
            'whereAndMap' => array_filter(array_values($data['whereAndMap'])),
            'whereOrMap' => array_filter(array_values($data['whereOrMap']))
        ];
    }
}
