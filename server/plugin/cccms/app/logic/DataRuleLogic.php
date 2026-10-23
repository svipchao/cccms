<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\DataRule;
use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\DataScope;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PageInput;
use plugin\cccms\support\RuleConflict;
use think\facade\Db;

/**
 * 数据权限规则逻辑（sys_data_rule）。
 *
 * 两类规则：
 *   - 行级  `action = row` → 用 field / operator / value 往查询里注入 where；
 *   - 字段级 `action = hidden / readonly / mask / encrypt` → hidden / mask / encrypt 作用于出参，
 *     readonly 作用于入参（强制剔除，防越权提交）。
 *
 * `table_name` 指定规则作用在哪张表（不含前缀），空 = 不限表（对所有接入模块生效）。
 * 指定了表的规则只在该表上生效，避免「字段名在别的表里不存在」把 SQL 拼错。
 *
 * 绑定维度：用户 / 岗位 / 部门（含下级）/ 角色，组合方式由 `bind_mode` 决定：
 *   - `or`（默认）：任一维度命中即生效（更宽）；
 *   - `and`：所有**已填写**的维度都要命中（更窄，用于「张三 且 在客服岗」这类表达）。
 * 四个维度都空 = 全局规则，与 `bind_mode` 无关。
 *
 * 与 data_scope 的关系见 DataScope 的类注释：预设档决定基线，自定义行级规则在其上叠加。
 */
final class DataRuleLogic
{
    /**
     * 可写入的列；dept_ids 是 JSON 列，单独归一化。
     *
     * 没有 `sort`：规则的先后顺序对生效结果没有影响（行级规则是 AND 叠加，
     * 字段级规则按字段各自处理），原先那个「排序」只影响列表行序、且列表里都不显示，
     * 属于会让人误以为有权重的假旋钮。列表按 id（即创建顺序）稳定输出。
     */
    private const FIELDS = [
        'name', 'user_id', 'post_id', 'dept_ids', 'role_id', 'bind_mode',
        'table_name', 'field', 'action', 'operator', 'value', 'value_type', 'remark',
    ];

    /** 界面需要的动作与操作符候选（与后端校验同一份来源） */
    public static function allowedActions(): array
    {
        return array_merge(['row'], DataScope::FIELD_ACTIONS);
    }

    /** 行级取值的动态变量候选（[{value,label}]，界面直接用） */
    public static function valueVars(): array
    {
        $out = [];
        foreach (DataScope::VALUE_VARS as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图；规则表是数据权限自身的元数据，模型声明为不参与
        $query = !empty($params['trashed']) ? DataRule::onlyTrashed() : DataRule::newScopedQuery();
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        if (!empty($params['field'])) {
            $query->where('field', 'like', '%' . $params['field'] . '%');
        }
        // 列头筛选支持多选，值形如 `sys_user,cccms:user`
        $tables = FilterInput::values($params['table_name'] ?? null);
        if ($tables !== []) {
            $query->whereIn('table_name', $tables);
        }
        $actions = FilterInput::values($params['action'] ?? null);
        if ($actions !== []) {
            $query->whereIn('action', $actions);
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        // 规则列表固定按创建顺序（id asc）输出，不提供列头排序
        $list = $query->page($page, $limit)->order('id', 'asc')->select()->toArray();

        return ['total' => $total, 'list' => self::decorate($list)];
    }

    public static function create(array $data): int
    {
        $data = self::prepare($data);

        if (($data['field'] ?? '') === '') {
            throw new ApiException(I18n::t('data_rule.field_required'), 422);
        }
        self::assertFieldInTable((string)($data['table_name'] ?? ''), (string)$data['field']);

        $data['action']      = (string)($data['action'] ?? 'row');
        $data['operator']    = (string)($data['operator'] ?? '=');
        $data['value']       = (string)($data['value'] ?? '');
        $data['value_type']  = (string)($data['value_type'] ?? 'static');
        $data['bind_mode']   = (string)($data['bind_mode'] ?? 'or');
        $data['create_time'] = date('Y-m-d H:i:s');

        self::assertNoDuplicate($data);

        return (int)DataRule::withoutGlobalScope()->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        $current = self::assertExists($id);

        $data = self::prepare($data);
        if (array_key_exists('field', $data) && $data['field'] === '') {
            throw new ApiException(I18n::t('data_rule.field_required'), 422);
        }

        // 只提交了表或字段其中之一时，用库里的另一项一起校验
        self::assertFieldInTable(
            (string)($data['table_name'] ?? $current['table_name']),
            (string)($data['field'] ?? $current['field'])
        );

        if (!$data) {
            return;
        }

        // 局部更新：用「库里的旧值 + 本次提交」拼出完整规则再查重
        self::assertNoDuplicate(array_merge($current, $data), $id);

        $data['update_time'] = date('Y-m-d H:i:s');
        DataRule::where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        self::assertExists($id);
        DataRule::where('id', $id)->delete();
    }

    /**
     * 表单候选数据。
     *
     * 一次拿全，避免规则页因为跨模块调用（用户/岗位/角色/部门）而依赖别的模块权限。
     */
    public static function options(): array
    {
        return [
            // 用户列表默认不返回（可能成千上万条）：改由 searchUsers() 按关键词懒加载
            'users'           => [],
            // 候选项一律取全量（岗位表声明不参与数据权限，这里显式写出意图）
            'posts'           => Post::withoutGlobalScope()->field('id,name')->order('sort', 'asc')->order('id', 'asc')->select()->toArray(),
            'roles'           => RoleLogic::tree(),
            // 绑定部门候选必须全量：规则是配置动作，不能因为「看不见某个部门」就绑不了
            'depts'           => DeptLogic::treeAll(),
            'tables'          => self::tables(),
            'actions'         => self::allowedActions(),
            'operators'       => DataScope::ROW_OPERATORS,
            'operator_labels' => DataScope::OPERATOR_LABELS,
            'value_types'     => DataScope::VALUE_TYPES,
            'value_vars'      => self::valueVars(),
        ];
    }

    /**
     * 绑定对象-用户候选（模糊搜索，默认无数据）。
     *
     * 两个用途：
     *   - 传 keyword：按 账号 / 昵称 模糊匹配，最多 20 条；
     *   - 传 ids：按 id 精确取回（编辑回显已绑定的用户，不受关键词影响）。
     *
     * @param array<int|string> $ids
     * @return array<int,array<string,mixed>>
     */
    public static function searchUsers(string $keyword, array $ids = []): array
    {
        $field   = 'id,username,nickname,status';
        $ids     = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn ($id) => $id > 0
        )));

        // 规则配置属于系统配置界面（不是业务数据浏览），用户选择器需要全量候选，
        // 否则「看不到的用户既选不了、已绑定的也显示成空名字」；因此显式跳出数据权限。
        if ($ids !== []) {
            return User::withoutGlobalScope()->whereIn('id', $ids)->field($field)->limit(50)->select()->toArray();
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        return User::withoutGlobalScope()
            ->field($field)
            ->where(static function ($q) use ($like): void {
                $q->where('username', 'like', $like)->whereOr('nickname', 'like', $like);
            })
            ->order('id', 'asc')
            ->limit(20)
            ->select()
            ->toArray();
    }

    /**
     * 受控表 + 字段候选（语义化名称直接取库表注释，不用手工维护映射）。
     *
     * 刻意不做进程内缓存：webman 的 worker 常驻，静态属性会跨请求存活，
     * 一旦缓存住，后台刚加的「受控表」就不会立刻出现在下拉里。
     * 这里每次实时读 information_schema + sys_data_scope_table（仅管理端低频调用）。
     *
     * @return array<int,array{table:string,full:string,label:string,fields:array<int,array{field:string,label:string,type:string}>}>
     */
    public static function tables(): array
    {
        $prefix = (string)Db::connect()->getConfig('prefix');
        $rows   = Db::query(
            'SELECT c.TABLE_NAME, t.TABLE_COMMENT, c.COLUMN_NAME, c.COLUMN_COMMENT, c.DATA_TYPE
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t
                 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME LIKE ?
              ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION',
            [$prefix . '%']
        );

        $guarded = DataScope::guardedTables();

        $out = [];
        foreach ($rows as $row) {
            $full = (string)$row['TABLE_NAME'];
            $bare = substr($full, strlen($prefix));
            // 只暴露「受控表」：其余表即使建了规则也不会生效，不放进候选
            if ($bare === '' || !in_array($bare, $guarded, true)) {
                continue;
            }

            $tableComment = trim((string)$row['TABLE_COMMENT']);
            $columnComment = trim((string)$row['COLUMN_COMMENT']);

            $out[$bare] ??= [
                'table'  => $bare,
                'full'   => $full,
                'label'  => $tableComment !== '' ? $tableComment : $bare,
                'fields' => [],
            ];
            $out[$bare]['fields'][] = [
                'field' => (string)$row['COLUMN_NAME'],
                'label' => $columnComment !== '' ? $columnComment : (string)$row['COLUMN_NAME'],
                'type'  => (string)$row['DATA_TYPE'],
            ];
        }

        return array_values($out);
    }

    /**
     * 校验 + 归一化（只处理提交里出现的键，因此新增和局部更新可以共用）。
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function prepare(array $data): array
    {
        $data = FilterInput::only($data, self::FIELDS);

        if (array_key_exists('action', $data)) {
            $action = (string)$data['action'];
            if (!in_array($action, self::allowedActions(), true)) {
                throw new ApiException(I18n::t('data_rule.unknown_action', ['action' => $action]), 422);
            }
            $data['action'] = $action;

            // 非行级规则不涉及操作符与取值，一并清空，避免列表里出现无意义的残留
            if ($action !== 'row') {
                $data['operator']   = '=';
                $data['value']      = '';
                $data['value_type'] = 'static';
            }
        }

        if (array_key_exists('table_name', $data)) {
            $table = trim((string)$data['table_name']);
            if ($table !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
                throw new ApiException(I18n::t('data_rule.invalid_table_name'), 422);
            }
            if ($table !== '' && !DataScope::canGuard($table)) {
                throw new ApiException(I18n::t('data_rule.target_table_not_controlled', ['table' => $table]), 422);
            }
            $data['table_name'] = $table;
        }

        if (array_key_exists('field', $data)) {
            // 字段名会被拼进 where 的列位置，只允许标识符
            $field = (string)$data['field'];
            if ($field !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                throw new ApiException(I18n::t('data_rule.invalid_field_name'), 422);
            }
            $data['field'] = $field;
        }

        if (array_key_exists('operator', $data)) {
            $operator = strtolower(trim((string)$data['operator']));
            if (!in_array($operator, DataScope::ROW_OPERATORS, true)) {
                throw new ApiException(I18n::t('data_rule.unknown_operator', ['operator' => $operator]), 422);
            }
            $data['operator'] = $operator;
        }

        if (array_key_exists('value_type', $data)) {
            $valueType = (string)$data['value_type'];
            if (!in_array($valueType, DataScope::VALUE_TYPES, true)) {
                throw new ApiException(I18n::t('data_rule.unknown_value_type', ['value_type' => $valueType]), 422);
            }
            $data['value_type'] = $valueType;
        }

        if (array_key_exists('bind_mode', $data)) {
            $mode = strtolower(trim((string)$data['bind_mode']));
            if (!in_array($mode, DataScope::BIND_MODES, true)) {
                throw new ApiException(I18n::t('data_rule.unknown_bind_mode', ['mode' => $mode]), 422);
            }
            $data['bind_mode'] = $mode;
        }

        if (array_key_exists('dept_ids', $data)) {
            $deptIds = $data['dept_ids'];
            if (is_string($deptIds)) {
                $decoded = json_decode($deptIds, true);
                $deptIds = is_array($decoded) ? $decoded : [];
            }
            $deptIds = array_values(array_unique(array_filter(
                array_map('intval', (array)$deptIds),
                static fn ($id) => $id > 0
            )));
            $data['dept_ids'] = $deptIds ? json_encode($deptIds) : null;
        }

        foreach (['user_id', 'post_id', 'role_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = max(0, (int)$data[$key]);
            }
        }

        return $data;
    }

    /**
     * 字段必须真实存在于目标表。
     *
     * 这是最容易被忽略的一类错配：规则写 `status` 却指定了没有该列的表，
     * 真正生效时才会在 SQL 上炸；这里在保存阶段就拦掉。
     */
    private static function assertFieldInTable(string $table, string $field): void
    {
        if ($table === '' || $field === '') {
            return;
        }

        $map = array_column(self::tables(), null, 'table');

        if (!isset($map[$table])) {
            throw new ApiException(I18n::t('data_rule.target_table_not_registered', ['table' => $table]), 422);
        }

        foreach ($map[$table]['fields'] as $item) {
            if ($item['field'] === $field) {
                return;
            }
        }

        throw new ApiException(I18n::t('data_rule.field_not_in_table', ['field' => $field, 'table' => $table]), 422);
    }

    /**
     * 把绑定 id 与目标表翻译成名称，列表就不必再查一遍。
     *
     * @param  array<int,array<string,mixed>> $list
     * @return array<int,array<string,mixed>>
     */
    private static function decorate(array $list): array
    {
        // 只对当前页出现的 id 补名，避免每次分页都全量拉取用户/岗位/角色/部门
        $userIds = [];
        $postIds = [];
        $roleIds = [];
        $deptIds = [];
        foreach ($list as $row) {
            if ((int)$row['user_id'] > 0) {
                $userIds[] = (int)$row['user_id'];
            }
            if ((int)$row['post_id'] > 0) {
                $postIds[] = (int)$row['post_id'];
            }
            if ((int)$row['role_id'] > 0) {
                $roleIds[] = (int)$row['role_id'];
            }
            $ids = json_decode((string)($row['dept_ids'] ?? 'null'), true);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $deptIds[] = (int)$id;
                }
            }
        }
        $userIds = array_values(array_unique($userIds));
        $postIds = array_values(array_unique($postIds));
        $roleIds = array_values(array_unique($roleIds));
        $deptIds = array_values(array_unique($deptIds));

        $users = $userIds === [] ? [] : User::withoutGlobalScope()->whereIn('id', $userIds)->column('username', 'id');
        $posts = $postIds === [] ? [] : Post::withoutGlobalScope()->whereIn('id', $postIds)->column('name', 'id');
        $roles = $roleIds === [] ? [] : Role::withoutGlobalScope()->whereIn('id', $roleIds)->column('name', 'id');
        // 部门参与数据权限，必须显式跳出：否则范围外的部门会显示成空名字
        $depts = $deptIds === [] ? [] : Dept::withoutGlobalScope()->whereIn('id', $deptIds)->column('name', 'id');
        // 表注释只查一次，避免逐行调用 tables()
        $tables = array_column(self::tables(), null, 'table');

        // 规则体检：条件互斥这类问题「配的时候看不出来、用的时候页面空白」，
        // 直接挂到列表上，比让人去记着跑命令行靠谱。需要全量规则才能比较，故单独查一次。
        $conflicts = RuleConflict::byRule(
            RuleConflict::check(DataRule::newScopedQuery()->select()->toArray())
        );

        foreach ($list as &$row) {
            $row['user_name'] = $users[(int)$row['user_id']] ?? '';
            $row['post_name'] = $posts[(int)$row['post_id']] ?? '';
            $row['role_name'] = $roles[(int)$row['role_id']] ?? '';

            // 绑定组合方式：与 DataScope::rules() 的判定保持一致（非法值按 or）
            $mode = strtolower(trim((string)$row['bind_mode']));
            $mode = in_array($mode, DataScope::BIND_MODES, true) ? $mode : 'or';
            $row['bind_mode']       = $mode;
            $row['bind_mode_label'] = DataScope::BIND_MODE_LABELS[$mode];

            $ids = json_decode((string)($row['dept_ids'] ?? 'null'), true);
            $ids = is_array($ids) ? array_map('intval', $ids) : [];
            $row['dept_ids']   = $ids;
            $row['dept_names'] = implode('、', array_values(array_filter(
                array_map(static fn ($id) => $depts[$id] ?? '', $ids)
            )));

            $table = (string)($row['table_name'] ?? '');
            $row['table_label'] = $table === ''
                ? ''
                : (string)($tables[$table]['label'] ?? $table);

            $row['conflicts'] = array_values(array_map(
                static fn ($item): array => ['type' => $item['type'], 'message' => $item['message']],
                $conflicts[(int)$row['id']] ?? []
            ));
        }
        unset($row);

        return $list;
    }

    private static function assertExists(int $id): array
    {
        $row = DataRule::withoutGlobalScope()->where('id', $id)->find();
        if (!$row) {
            throw new ApiException(I18n::t('data_rule.not_found'), 404);
        }

        return $row->toArray();
    }

    /**
     * 拒绝重复规则。
     *
     * 为什么不用数据库唯一索引：规则可以被软删、也可能在回收站里被恢复，
     * 唯一索引会把「删掉再建一条一样的」也挡住（`uk_type` 已经有这个坑，
     * 见 `docs/13-数据模型.md` 的唯一键陷阱）。规则表数据量小、写入频率低，
     * 写入前查重 + 保留回收站恢复能力更划算。
     *
     * 判重口径（同租户内、只看未删除行）：
     *   - 绑定对象：`user_id` / `post_id` / `role_id` / `dept_ids`（按集合比较，忽略顺序）+ `bind_mode`
     *   - 作用点：`table_name` + `field` + `action`
     *   - 行级规则额外比较 `operator` + `value` + `value_type`
     *     （同一字段可以有多个行级条件，例如 `dept_id = 3` 与 `dept_id = 5`）
     *
     * @param array<string,mixed> $row      归一化后的完整规则
     * @param int                 $exceptId 更新时排除自身
     */
    private static function assertNoDuplicate(array $row, int $exceptId = 0): void
    {
        $action = (string)($row['action'] ?? 'row');

        $query = DataRule::withoutGlobalScope()
            ->where('user_id', (int)($row['user_id'] ?? 0))
            ->where('post_id', (int)($row['post_id'] ?? 0))
            ->where('role_id', (int)($row['role_id'] ?? 0))
            ->where('bind_mode', (string)($row['bind_mode'] ?? 'or'))
            ->where('table_name', (string)($row['table_name'] ?? ''))
            ->where('field', (string)($row['field'] ?? ''))
            ->where('action', $action);

        if ($action === 'row') {
            $query->where('operator', (string)($row['operator'] ?? '='))
                ->where('value', (string)($row['value'] ?? ''))
                ->where('value_type', (string)($row['value_type'] ?? 'static'));
        }
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }

        $candidates = $query->field('id, dept_ids')->select()->toArray();
        if ($candidates === []) {
            return;
        }

        $deptKey = self::deptKey($row['dept_ids'] ?? null);
        foreach ($candidates as $candidate) {
            if (self::deptKey($candidate['dept_ids'] ?? null) === $deptKey) {
                throw new ApiException(I18n::t('data_rule.duplicate'), 422);
            }
        }
    }

    /**
     * 部门绑定的集合键：JSON 数组 → 排序去重后的 `1,3,5`。
     *
     * 不能直接比较 JSON 字符串：`[3,1]` 与 `[1,3]` 是同一组绑定，
     * 字符串比较会把它们当成两条规则，等于查重失效。
     *
     * @param mixed $deptIds
     */
    private static function deptKey($deptIds): string
    {
        if (is_string($deptIds)) {
            $decoded = json_decode($deptIds, true);
            $deptIds = is_array($decoded) ? $decoded : [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array)$deptIds),
            static fn (int $id): bool => $id > 0
        )));
        sort($ids);

        return implode(',', $ids);
    }
}
