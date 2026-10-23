<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 权限 slug 的归属判定（**唯一口径**）。
 *
 * 为什么需要它：同一件事（「这个按钮属于哪个菜单」）原先在三个地方各写一遍，规则还不完全一样 ——
 * `PermScanner` 用「最长前缀」、`MenuLogic` 用「等于或前缀」、`OpenApi` 用「前两段」。
 * 三处一旦漂移，表现是「菜单里看不到按钮」「接口文档分组冒出 `cccms:job` 这种 key 原文」。
 *
 * 三种判定语义**故意分开**，不要合并成一个：
 *
 * | 方法 | 语义 | 用在 |
 * |------|------|------|
 * | `belongsTo()` | `x` 与 `x:action` 都算属于 `x` | 开发态菜单前缀过滤、节点归属判断 |
 * | `longestOwner()` | 只看 `x:...:action`，多个命中取最长，且 owner 至少两级 | 按钮节点同步时找父菜单（`cccms:recycle:clean` 优先挂 `cccms:recycle`；`cccms` 这类目录节点不算归属） |
 * | `groupOf()` | 机械取前两段 | 接口文档分组（`cccms:user:index` → `cccms:user`） |
 */
final class Slug
{
    /**
     * `$slug` 是否属于节点 `$node`：完全相等，或形如 `$node:action`。
     *
     * @param array<int,string>|string $nodes
     */
    public static function belongsTo(string $slug, array|string $nodes): bool
    {
        if ($slug === '') {
            return false;
        }

        foreach ((array)$nodes as $node) {
            if ($node === '') {
                continue;
            }
            if ($slug === $node || str_starts_with($slug, $node . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 在候选菜单 slug 里找 `$slug` 的最长前缀归属。
     *
     * **单段节点（`cccms` / `shop`）一律不算归属**：那只是插件命名空间 / 一级目录节点，
     * 库里所有业务按钮都长在它下面，匹配上等于「全都挂到根」—— 表现得像命中，
     * 实际在角色授权树里是一个看不见展开项的目录，勾了也不生效。返回 null 让调用方
     * 明确报「找不到归属菜单」（`PermScanner::syncButtons()` 就是这么做的），比静默挂错强。
     *
     * @param array<int,string> $nodes
     */
    public static function longestOwner(string $slug, array $nodes): ?string
    {
        $best = null;
        foreach ($nodes as $node) {
            // 至少两级（`plugin:module`）才可能是可授权的菜单；`cccms:file:upload:chunk` 这类
            // 多一级动作的按钮，仍然归到 `cccms:file`
            if ($node === '' || !str_contains($node, ':') || !str_starts_with($slug, $node . ':')) {
                continue;
            }
            if ($best === null || strlen($node) > strlen($best)) {
                $best = $node;
            }
        }

        return $best;
    }

    /** 取前两段：`cccms:user:index` → `cccms:user`；不足两段时原样返回 */
    public static function groupOf(string $slug): string
    {
        $parts = explode(':', $slug);

        return implode(':', array_slice($parts, 0, 2));
    }

    /** 最后一段：`cccms:user:index` → `index`（动作名，用于兜底展示） */
    public static function actionOf(string $slug): string
    {
        $parts = explode(':', $slug);
        $last  = end($parts);

        return $last === false ? '' : (string)$last;
    }
}
