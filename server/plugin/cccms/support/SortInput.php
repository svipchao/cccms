<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\db\BaseQuery;

/**
 * 列表排序入参解析。
 *
 * 前端列头排序统一下发 `sort`（列的 `prop`）+ `order`（`asc` / `desc`）。
 *
 * **字段名只能来自各 Logic 声明的白名单**（`prop => 真实列名`）：
 *   - 前端传来的值只用于**查表**，永远不会直接拼进 `order()`；
 *   - 未登记的列（计算列 / 合成列 / 人工填的假字段）一律回落默认排序，
 *     既不报 500，也不会因为未知列拼进 SQL 而泄露表结构。
 *
 * 命中排序时再追加一次主键排序：同值行在翻页时顺序不稳定，会出现
 * 「第 1 页和第 2 页重复/漏行」的错觉。
 */
final class SortInput
{
    /**
     * 把排序条件挂到查询上。
     *
     * @param BaseQuery            $query        列表查询
     * @param array<string,mixed>  $params       请求参数（含 `sort` / `order`）
     * @param array<string,string> $sortable     可排序列白名单：前端 prop => 真实列名
     * @param string               $defaultField 未指定排序时的默认列
     * @param string               $defaultDir   默认方向：asc / desc
     * @param string               $tieDir       次级排序（主键）方向：asc / desc
     */
    public static function apply(
        BaseQuery $query,
        array $params,
        array $sortable,
        string $defaultField = 'id',
        string $defaultDir = 'desc',
        string $tieDir = 'desc',
    ): void {
        [$field, $dir] = self::resolve($params, $sortable, $defaultField, $defaultDir);

        $query->order($field, $dir);

        // 主键兜底，保证同值时翻页顺序稳定；排序列本身是主键就不必重复
        if ($field !== 'id') {
            $query->order('id', $tieDir);
        }
    }

    /**
     * 解析出最终排序字段与方向（与查询解耦，便于单测）。
     *
     * 返回的字段**一定**来自白名单或默认值，前端入参只用于命中白名单。
     *
     * @param array<string,mixed>  $params
     * @param array<string,string> $sortable
     *
     * @return array{0:string,1:string} [列名, asc|desc]
     */
    public static function resolve(
        array $params,
        array $sortable,
        string $defaultField = 'id',
        string $defaultDir = 'desc',
    ): array {
        $prop = is_string($params['sort'] ?? null) ? trim($params['sort']) : '';
        $dir  = strtolower(is_string($params['order'] ?? null) ? trim($params['order']) : '');

        if ($prop !== '' && isset($sortable[$prop])) {
            return [$sortable[$prop], $dir === 'asc' ? 'asc' : 'desc'];
        }

        // 未指定 / 未知列：保持页面原有的默认排序
        return [$defaultField, $defaultDir === 'asc' ? 'asc' : 'desc'];
    }
}
