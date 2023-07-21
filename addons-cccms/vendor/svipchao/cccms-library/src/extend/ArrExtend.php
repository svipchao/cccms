<?php
declare (strict_types=1);

namespace cccms\extend;

class ArrExtend
{
    /**
     * 一维数组去重
     * @param array $array
     * @return array
     */
    public static function getOneUnique(array $array = []): array
    {
        return array_keys(array_flip($array));
    }

    /**
     * 二维数组去重 1 不保留键
     * @param array $array
     * @param string $field
     * @return array
     */
    public static function getTwoUnique1(array $array = [], string $field = 'id'): array
    {
        $list = array_filter($array);
        // 重新排序
        return array_merge(array_combine(array_column($list, $field), $list), []);
    }

    /**
     * 二维数组去重 2 保留键
     * @param array $array
     * @param string $field
     * @return array
     */
    public static function getTwoUnique2(array $array = [], string $field = 'id'): array
    {
        $list = array_filter($array);
        return array_combine(array_column($list, $field), $list);
    }

    /**
     * 多维数组转为一维数组
     * @param array $array
     * @param string $children
     * @return array
     */
    public static function getTreeReduce(array $array = [], string $children = 'children'): array
    {
        static $list = [];
        foreach ($array as $val) {
            $son = $val[$children] ?? [];
            unset($val[$children]);
            $list[] = $val;
            if ($son) $list = self::getTreeReduce($son, $children);
        }
        return $list;
    }

    /**
     * 二维数组根据某个字段排序
     * @param array $array
     * @param string $keys
     * @param mixed $sort SORT_ASC 升序   SORT_ASC 降序
     * @return array 排序后的数组
     */
    public static function arraySort(array $array, string $keys, $sort = SORT_ASC): array
    {
        $keysValue = [];
        foreach ($array as $k => $v) {
            $keysValue[$k] = $v[$keys];
        }
        array_multisort($keysValue, $sort, $array);
        return $array;
    }

    /**
     * 读取指定节点的所有孩子节点
     * @param array $array
     * @param mixed $value 当前主键值
     * @param bool $withSelf 是否包含自身
     * @param string $currentKey 当前主键
     * @param string $parentKey 父级主键
     * @return array
     */
    public static function getChildren(array $array = [], $value = 0, bool $withSelf = false, string $currentKey = 'id', string $parentKey = 'pid'): array
    {
        $arr = [];
        foreach ($array as $val) {
            if (!isset($val[$currentKey])) continue;
            if ($val[$parentKey] == $value) {
                $arr[] = $val;
                $arr = array_merge($arr, self::getChildren($array, $val[$currentKey], $withSelf, $currentKey, $parentKey));
            } elseif ($withSelf && $val[$currentKey] == $value) {
                $arr[] = $val;
            }
        }
        return $arr;
    }

    /**
     * 读取指定节点的所有孩子节点ID
     * @param array $array
     * @param mixed $value 当前主键值
     * @param bool $withSelf 是否包含自身
     * @param string $currentKey 当前主键
     * @param string $parentKey 父级主键
     * @return array
     */
    public static function getChildrenIds(array $array = [], $value = 0, bool $withSelf = false, string $currentKey = 'id', string $parentKey = 'pid'): array
    {
        $childrenList = self::getChildren($array, $value, $withSelf, $currentKey, $parentKey);
        $childrenIds = [];
        foreach ($childrenList as $v) {
            $childrenIds[] = $v[$currentKey];
        }
        return $childrenIds;
    }

    /**
     * 以一维数组返回数据树
     * @param array $array
     * @param string $currentKey 当前主键
     * @param string $parentKey 父级主键
     * @param string $children 子级字段
     * @param string $mark 记号
     * @param int $level 级别
     * @return array
     */
    public static function getTreeList(array $array = [], string $currentKey = 'id', string $parentKey = 'pid', string $children = 'children', string $mark = '├　', int $level = 0): array
    {
        $list = [];
        foreach ($array as &$val) {
            $son = $val[$children] ?? [];
            unset($val[$children]);
            $val['mark'] = str_repeat($mark, $level);
            $list[] = $val;
            if ($son) $list = array_merge($list, self::getTreeList($son, $currentKey, $parentKey, $children, $mark, $level + 1));
        }
        return $list;
    }

    /**
     * 以多维数组返回数据树
     * @param array $array
     * @param string $currentKey 当前主键
     * @param string $parentKey 父级主键
     * @param string $children 子级字段
     * @return array
     */
    public static function getTreeArray(array $array = [], string $currentKey = 'id', string $parentKey = 'pid', string $children = 'children'): array
    {
        $tmp = self::getTwoUnique2($array, $currentKey);
        $tree = [];
        foreach ($array as $value) {
            if (isset($value[$parentKey]) && isset($tmp[$value[$parentKey]])) {
                $tmp[$value[$parentKey]][$children][] = &$tmp[$value[$currentKey]];
            } else {
                $tree[] = &$tmp[$value[$currentKey]];
            }
        }
        return $tree;
    }
}