<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 树 / 批量 id 通用工具。
 *
 * 散落在多个 Logic 里的同构实现（子树 BFS 展开、递归拼树、批量 id 归一化）
 * 收敛到这里，避免后续修一处漏一处 —— 例如「父节点成环校验」此前 CategoryLogic
 * 有、DeptLogic 漏了，正是同构逻辑分叉的结果。
 */
final class Tree
{
    /**
     * 子树展开：给定 `id => parent_id` 映射，返回起始 id 集合 + 其所有后代。
     *
     * 起始 id 会先归一化（去重、去非正整数）；空集直接返回空数组。
     *
     * @param array<int|string,int> $parents id => parent_id
     * @param array<int,mixed>      $ids     起始 id 集合（自身 + 后代）
     * @return int[]
     */
    public static function subtreeIds(array $parents, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        // 先转成 children 邻接表，BFS 时就不用每次全表扫 parent_id
        $children = [];
        foreach ($parents as $id => $pid) {
            $children[(int)$pid][] = (int)$id;
        }

        $result = $ids;
        $queue  = $ids;
        // 用哈希判重：`in_array()` 在大子树（部门 / 分类上万节点）下会退化成 O(n²)
        $seen = array_fill_keys($ids, true);
        while ($queue !== []) {
            $current = (int)array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (!isset($seen[$child])) {
                    $seen[$child] = true;
                    $result[]     = $child;
                    $queue[]      = $child;
                }
            }
        }

        return $result;
    }

    /**
     * 递归拼树：把平铺行（含 `id` / `parent_id`）拼成树。
     *
     * `$visibleIds` 传入后，「父节点不在可见集合里」的节点会被当作顶层 ——
     * 数据范围过滤后必然出现这种情况（只看子树 / 只看自己所属部门），
     * 不处理的话树会整棵变空。
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,int>|null            $visibleIds
     * @return array<int,array<string,mixed>>
     */
    public static function buildTree(array $items, int $parentId = 0, ?array $visibleIds = null): array
    {
        $tree = [];
        foreach ($items as $item) {
            $pid    = (int)$item['parent_id'];
            $isRoot = $visibleIds !== null && $parentId === 0
                ? ($pid === 0 || !in_array($pid, $visibleIds, true))
                : $pid === $parentId;

            if (!$isRoot) {
                continue;
            }

            $item['children'] = self::buildTree($items, (int)$item['id'], $visibleIds);
            $tree[]           = $item;
        }

        return $tree;
    }

    /**
     * 归一化批量 id：去重、去非正整数；空则抛「请选择」。
     *
     * @param array<int,mixed> $ids
     * @return int[]
     */
    public static function batchIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            throw new ApiException(I18n::t('common.select_required'), 422);
        }

        return $ids;
    }
}
