<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\User;
use plugin\cccms\app\model\UserDept;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\Tree;

/**
 * 部门逻辑。
 *
 * 查询统一走 `Dept` 模型：部门表**参与**数据权限（见 `app/model/Dept.php`），
 *   - 「仅本人」= 我所属的部门（不含下级）；
 *   - 「本部门及以下」= 我所属部门及其子树。
 *
 * 配置类界面（数据权限规则里的「绑定部门」候选）需要全量，走 `treeAll()`。
 */
final class DeptLogic
{
    /**
     * 可写字段白名单（防 Mass Assignment）。
     *
     * 刻意不含 `id` / `tenant_id` / `create_time` / `delete_time`。
     */
    private const FIELDS = ['parent_id', 'name', 'leader', 'phone', 'email', 'sort', 'status'];

    /**
     * 部门树（按当前用户的数据范围）。
     *
     * `$trashed=true` 时返回**平铺**的已删部门：回收站里的节点，其父节点可能还活着，
     * 只拿已删行去拼树会把「父未删、子已删」的节点直接漏掉 —— 反而恢复不了了。
     */
    public static function tree(bool $trashed = false): array
    {
        $all = ($trashed ? Dept::onlyTrashed() : Dept::newScopedQuery())
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        if ($trashed) {
            return $all;
        }

        // 作用域过滤后，可见节点的父节点可能不在集合内（例如只看自己所属的二级部门），
        // 需要把这类节点提升为顶层，否则整棵树会因为找不到 parent_id = 0 的根而变成空的
        $visibleIds = array_map(static fn (array $row): int => (int)$row['id'], $all);

        return Tree::buildTree($all, 0, $visibleIds);
    }

    /**
     * 全量部门树（**不做**数据范围过滤）。
     *
     * 给配置界面用：数据权限规则的「绑定部门」候选项必须能看到所有部门，
     * 否则「看不见的部门既绑不了、已绑定的也解不开」。这类动作属于系统配置，
     * 不是浏览业务数据，因此显式跳出作用域。
     */
    public static function treeAll(): array
    {
        $all = Dept::withoutGlobalScope()
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return Tree::buildTree($all, 0);
    }

    public static function create(array $data): int
    {
        $data = FilterInput::only($data, self::FIELDS);

        // 新增还没有归属，插入语句不需要数据权限条件
        return (int)Dept::withoutGlobalScope()->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::FIELDS);

        self::assertInScope($id);

        // 不允许把自己或自己的下级设为上级，否则部门树成环
        if (array_key_exists('parent_id', $data)) {
            $parentId = (int)$data['parent_id'];
            if ($parentId === $id || in_array($parentId, self::subtreeIds($id), true)) {
                throw new ApiException(I18n::t('dept.parent_invalid'), 422);
            }
        }

        Dept::newScopedQuery()->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        self::assertInScope($id);

        // 子部门 / 成员判定必须看**全量**：范围外的子部门同样会造成孤儿数据
        if (Dept::withoutGlobalScope()->where('parent_id', $id)->count() > 0) {
            throw new ApiException(I18n::t('dept.has_children'), 422);
        }

        // 只统计**未软删**的用户：用户进回收站后 `sys_user_dept` 会刻意保留（便于恢复），
        // 若连回收站用户也算占用，管理员在界面上看不到任何人却永远删不掉这个部门。
        $memberIds = array_map('intval', UserDept::where('dept_id', $id)->column('user_id'));
        if ($memberIds !== [] && User::withoutAllScopes('部门占用判定必须跨租户统计全部在位用户')
            ->whereIn('id', $memberIds)
            ->whereNull('delete_time')
            ->count() > 0) {
            throw new ApiException(I18n::t('dept.has_users'), 422);
        }

        // 软删除：进回收站；dept_role 保留，恢复后部门角色原样回来
        Dept::destroy($id);
    }

    /**
     * 数据范围校验：越权 403；不存在（或已进回收站）沿用原来的提示。
     *
     * 范围由 `Dept` 模型的全局作用域注入，`withoutGlobalScope()` 那次查库只用于区分两者。
     */
    private static function assertInScope(int $id): void
    {
        if (Dept::newScopedQuery()->where('id', $id)->count() > 0) {
            return;
        }

        if (!Dept::withoutGlobalScope()->where('id', $id)->find()) {
            throw new ApiException(I18n::t('dept.not_found'), 404);
        }

        throw new ApiException(I18n::t('dept.no_permission'), 403);
    }

    /** 部门自身 + 所有后代 id（用于成环校验） */
    private static function subtreeIds(int $id): array
    {
        return Tree::subtreeIds(Dept::withoutGlobalScope()->column('parent_id', 'id'), [$id]);
    }
}
