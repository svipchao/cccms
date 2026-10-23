<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\facade\Db;
use Throwable;

/**
 * 孤儿数据体检。
 *
 * 本项目的关联靠**应用层维护**（`sys_*` 全套没有物理外键，见 `docs/13-数据模型.md`），
 * 单条删除都在 `Logic` 里同步清理；但批量删除、回收站彻底删除、租户删除这些路径
 * 一旦漏掉某张关联表，残留行不会报错、也不会被任何列表展示出来 —— 只会在
 * 「权限莫名失效」「通知数对不上」这类现象里慢慢暴露。
 *
 * 判定口径：**父行完全不存在**才算孤儿。父行在回收站里（软删）不算 ——
 * 恢复父行后子行要能继续用，这正是「软删 + 保留关联」的设计前提。
 */
final class OrphanChecker
{
    /**
     * 通用「子表列 → 父表」关系。
     *
     * 键名格式 `子表.列`，用于输出定位；表名不带 `sys_` 前缀（由 `Db::name()` 加）。
     *
     * @var array<int,array{key:string,title:string,table:string,column:string,parent:string}>
     */
    private const RELATIONS = [
        ['key' => 'user_role.user_id',   'title' => '用户-角色：用户已不存在',   'table' => 'user_role',   'column' => 'user_id',   'parent' => 'user'],
        ['key' => 'user_role.role_id',   'title' => '用户-角色：角色已不存在',   'table' => 'user_role',   'column' => 'role_id',   'parent' => 'role'],
        ['key' => 'user_post.user_id',   'title' => '用户-岗位：用户已不存在',   'table' => 'user_post',   'column' => 'user_id',   'parent' => 'user'],
        ['key' => 'user_post.post_id',   'title' => '用户-岗位：岗位已不存在',   'table' => 'user_post',   'column' => 'post_id',   'parent' => 'post'],
        ['key' => 'user_dept.user_id',   'title' => '用户-部门：用户已不存在',   'table' => 'user_dept',   'column' => 'user_id',   'parent' => 'user'],
        ['key' => 'user_dept.dept_id',   'title' => '用户-部门：部门已不存在',   'table' => 'user_dept',   'column' => 'dept_id',   'parent' => 'dept'],
        ['key' => 'role_node.role_id',   'title' => '角色授权：角色已不存在',   'table' => 'role_node',   'column' => 'role_id',   'parent' => 'role'],
        ['key' => 'role_node.node',      'title' => '角色授权：权限节点已不存在', 'table' => 'role_node',   'column' => 'node',      'parent' => 'menu'],
        ['key' => 'dict_data.type_id',   'title' => '字典数据：类型已不存在',   'table' => 'dict_data',   'column' => 'type_id',   'parent' => 'dict_type'],
        ['key' => 'notice_target.notice_id', 'title' => '通知目标：通知已不存在', 'table' => 'notice_target', 'column' => 'notice_id', 'parent' => 'notice'],
        ['key' => 'notice_read.notice_id',   'title' => '通知已读：通知已不存在', 'table' => 'notice_read',   'column' => 'notice_id', 'parent' => 'notice'],
        ['key' => 'notice_read.user_id',     'title' => '通知已读：用户已不存在', 'table' => 'notice_read',   'column' => 'user_id',   'parent' => 'user'],
        ['key' => 'menu.parent_id',      'title' => '菜单：父节点已不存在',     'table' => 'menu',        'column' => 'parent_id', 'parent' => 'menu'],
        ['key' => 'category.parent_id',  'title' => '分类：父分类已不存在',     'table' => 'category',    'column' => 'parent_id', 'parent' => 'category'],
    ];

    /**
     * 执行体检。
     *
     * @param int $sampleLimit 每项最多返回几个示例 id（便于人工定位）
     * @return array{checked:int,issues:array<int,array{key:string,title:string,count:int,samples:array<int,int>}>}
     */
    public static function scan(int $sampleLimit = 5): array
    {
        $issues = [];
        $checked = 0;

        foreach (self::RELATIONS as $relation) {
            $checked++;
            $count = self::countMissing($relation['table'], $relation['column'], $relation['parent']);
            if ($count === 0) {
                continue;
            }
            $issues[] = [
                'key'     => $relation['key'],
                'title'   => $relation['title'],
                'count'   => $count,
                'samples' => self::sampleMissing($relation['table'], $relation['column'], $relation['parent'], $sampleLimit),
            ];
        }

        foreach (self::boundRuleChecks() as $check) {
            $checked++;
            if ($check['count'] === 0) {
                continue;
            }
            $issues[] = $check;
        }

        return ['checked' => $checked, 'issues' => $issues];
    }

    /**
     * 数据权限规则的三个绑定维度（任一非 0 就必须存在对应行）。
     *
     * 规则表用 `0` 表示「不按该维度绑定」，因此不能当普通外键处理。
     *
     * @return array<int,array{key:string,title:string,count:int,samples:array<int,int>}>
     */
    private static function boundRuleChecks(): array
    {
        $out = [];
        $dimensions = [
            'user_id' => ['user', '数据权限规则：绑定的用户已不存在'],
            'post_id' => ['post', '数据权限规则：绑定的岗位已不存在'],
            'role_id' => ['role', '数据权限规则：绑定的角色已不存在'],
        ];

        foreach ($dimensions as $column => [$parent, $title]) {
            $count = self::countMissing('data_rule', $column, $parent, true);
            $out[] = [
                'key'     => 'data_rule.' . $column,
                'title'   => $title,
                'count'   => $count,
                'samples' => $count > 0 ? self::sampleMissing('data_rule', $column, $parent, 5, true) : [],
            ];
        }

        return $out;
    }

    /** 统计「列值指向的父行不存在」的行数 */
    private static function countMissing(string $table, string $column, string $parent, bool $positiveOnly = false): int
    {
        $query = Db::name($table)
            ->alias('t')
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM `' . Db::name($parent)->getTable() . '` p WHERE p.id = t.`' . $column . '`)'
            );

        if ($positiveOnly || $column === 'parent_id') {
            $query->where('t.' . $column, '>', 0);
        }

        try {
            return (int)$query->count();
        } catch (Throwable) {
            // 表还没建（插件未安装）时跳过，不要因为体检把命令打挂
            return 0;
        }
    }

    /**
     * @return array<int,int>
     */
    private static function sampleMissing(
        string $table,
        string $column,
        string $parent,
        int $limit,
        bool $positiveOnly = false
    ): array {
        $query = Db::name($table)
            ->alias('t')
            ->field('t.id')
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM `' . Db::name($parent)->getTable() . '` p WHERE p.id = t.`' . $column . '`)'
            );

        if ($positiveOnly || $column === 'parent_id') {
            $query->where('t.' . $column, '>', 0);
        }

        try {
            return array_map('intval', array_column($query->limit($limit)->select()->toArray(), 'id'));
        } catch (Throwable) {
            return [];
        }
    }
}
