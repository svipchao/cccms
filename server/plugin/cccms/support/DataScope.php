<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use InvalidArgumentException;
use think\facade\Db;
use Throwable;
use WeakMap;

/**
 * 数据权限：行级（where 注入）+ 字段级（hidden / readonly / mask / encrypt）。
 *
 * 绑定维度：用户 / 岗位 / 部门（含下级）/ 角色；单条规则内按 `bind_mode` 组合：
 * `or`（默认）任一命中即生效，`and` 要求所有**已填写**的维度都命中（空维度 = 不限）。
 *
 * 档位（role.data_scope）：1 全部数据；2 本部门及以下；3 本部门；4 仅本人；5 自定义。
 * 2/3/4 先加预设基线再叠加自定义行级规则；5 没有基线，一条规则都没命中就看不到数据
 * （fail-closed，不该隔离的表应在模型上声明 `$dataScope = false`）。多角色取最宽松值。
 *
 * 角色口径：规则绑定与动态变量用「有效角色（直连 + 祖先）」；档位只按直连角色计算，
 * 避免子角色因父角色的「全部数据」而隐式扩权。
 *
 * 字段级规则与档位无关，绑定匹配即生效（超管除外）。字段名由调用方保证可信，
 * where 全部参数化。
 */
final class DataScope
{
    /**
     * 请求级 scope plan：一次请求内的所有查询共用一份用户维度数据。
     *
     * 此前每次 `row()` / `rules()` 调用都要查一遍角色、岗位、部门、规则（固定约 8 条 SQL）；
     * Logic 层显式调用时每请求只有一两次，无感；但接入模型全局作用域后**每条模型查询都会触发**，
     * 必须收敛为「每请求解析一次」。
     *
     * 键用 UserContext **实例**而不是用户 id：
     *   - webman 常驻进程，静态数组键（用户 id）会跨请求存活，权限变更后不生效；
     *   - UserContext 每次请求由 CheckLogin 重建，WeakMap 随其被回收自动释放，
     *     天然是请求级缓存；也不存在 spl_object_id 复用导致串数据的风险。
     *
     * @var WeakMap<UserContext,array<string,mixed>>|null
     */
    private static ?WeakMap $plans = null;

    /**
     * 字段级动作白名单。
     *
     * 保存时 `DataRuleLogic::prepare()` 已按此校验；**执行期还要再校验一次**
     * （见 `compileFieldRules()`）：手改 SQL、旧版本数据、迁移异常都可能留下非法动作，
     * 而「遇到未知动作就跳过」等于把本该隐藏 / 掩码的字段原样返回 —— 静默泄漏。
     */
    public const FIELD_ACTIONS = ['hidden', 'readonly', 'mask', 'encrypt'];

    /**
     * 绑定维度的组合方式（`sys_data_rule.bind_mode`）。
     *
     *   - `or`（默认）：任一维度命中即生效 —— 更宽；
     *   - `and`：所有**已填写**的维度都要命中 —— 更窄，用于表达「张三 且 在客服岗」。
     *
     * 只在「有绑定」时有意义：四个维度都空 = 全局规则，与 mode 无关。
     */
    public const BIND_MODES = ['or', 'and'];

    /** 组合方式的中文名（界面展示用） */
    public const BIND_MODE_LABELS = [
        'or'  => '任一命中',
        'and' => '全部命中',
    ];

    /** 行级操作符白名单（供规则管理界面校验，避免 operator 被注入） */
    public const ROW_OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'in', 'between'];

    /** 操作符的语义化名称（界面展示用，与 ROW_OPERATORS 一一对应） */
    public const OPERATOR_LABELS = [
        '='       => '等于',
        '!='      => '不等于',
        '<>'      => '不等于',
        '>'       => '大于',
        '>='      => '大于等于',
        '<'       => '小于',
        '<='      => '小于等于',
        'like'    => '包含',
        'in'      => '属于',
        'between' => '介于',
    ];

    /**
     * 行级取值的类型。
     *   - static ：取值就是字面量（可逗号分隔多个）；
     *   - dynamic：取值里的 `{变量}` 在执行时按当前用户解析（见 VALUE_VARS）。
     */
    public const VALUE_TYPES = ['static', 'dynamic'];

    /**
     * 动态变量：占位符 => 说明。
     *
     * 用法：行级规则把取值类型选为「动态变量」，取值里写占位符即可，例如
     *   `dept_id in {dept.subtree}`  → 本部门及其所有下级
     * 多个变量 / 字面量可混用（逗号分隔），会合并成一个集合走 `IN`。
     */
    public const VALUE_VARS = [
        '{user.id}'      => '当前用户 ID',
        '{dept.ids}'     => '我所属的部门（不含下级）',
        '{dept.subtree}' => '我所属部门及其所有下级',
        '{post.ids}'     => '我的岗位',
        '{role.ids}'     => '我的角色',
    ];

    /**
     * 受控表兜底名单（不含前缀）。
     *
     * 「某张表能否做数据权限」本质上取决于两张表是否真的接了数据权限：
     * 模型有没有参与（`BaseModel::$dataScope`）、业务层有没有调用本类。
     * 正式名单由 `sys_data_scope_table` 可视化维护；这里只在该表不存在 /
     * 没有任何启用行时兜底，避免升级过程中退化成「全部表都可配」。
     *
     * 受控表控制的是**自定义规则**：只有受控表才会出现在规则页的「目标表」候选中，
     * 保存与执行时也才会放行。预设基线（仅本人 / 本部门）则由模型声明决定 ——
     * 例如 `sys_dept` 不登记受控（不能配自定义规则），但部门页仍按「本部门及以下」收窄。
     */
    public const DEFAULT_TABLES = ['user'];

    /** 当前受控表（不含前缀，仅启用中的）；表不存在时回退 DEFAULT_TABLES */
    public static function guardedTables(): array
    {
        // 请求内复用 scope plan；CLI / 登录前没有用户上下文时直接查库
        $user = self::currentUser();

        return $user !== null ? self::plan($user)['tables'] : self::queryGuardedTables();
    }

    /**
     * 受控表直查（构建 plan 时用，不做缓存）。
     *
     * `$tenantId` 非 null 时只取该租户登记的行 —— 受控表名单属于租户自己的配置，
     * 混读会让 A 租户的登记决定 B 租户能不能配规则。
     * null（CLI / 无用户上下文）表示平台级处理，不加租户条件。
     */
    private static function queryGuardedTables(?int $tenantId = null): array
    {
        try {
            $query = Db::name('data_scope_table')->where('status', 1);
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }
            $tables = $query->column('table_name');
        } catch (Throwable) {
            return self::DEFAULT_TABLES;
        }

        $tables = array_values(array_filter(
            array_map('strval', (array)$tables),
            static fn (string $t): bool => $t !== ''
        ));

        return $tables ?: self::DEFAULT_TABLES;
    }

    /** 当前请求的用户上下文；CLI、登录前、公共路由返回 null */
    public static function currentUser(): ?UserContext
    {
        $request = function_exists('request') ? request() : null;
        $user    = $request?->user;

        return $user instanceof UserContext ? $user : null;
    }

    /**
     * 以指定身份执行一段代码（`null` = 平台 / 无身份），执行完**恢复原身份**。
     *
     * 为什么需要：定时任务的「立即执行」在**当前 worker 内**跑，沿用操作者身份时，
     * 同一个任务「手动」与「定时」会处理不同数据集、把数据写到不同租户（P1-2）。
     * 执行身份应当只由任务归属决定，操作者只体现在日志的 `source=manual` 上。
     *
     * 用 try/finally 恢复：webman 的 worker 常驻，身份一旦泄漏给后续请求就是越权。
     * 无请求上下文（CLI / 调度进程）时直接执行 —— 那里本来就是平台身份。
     */
    public static function runAs(?UserContext $user, callable $fn): mixed
    {
        $request = function_exists('request') ? request() : null;
        if ($request === null) {
            return $fn();
        }

        $previous = $request->user ?? null;
        $request->user = $user;

        try {
            return $fn();
        } finally {
            $request->user = $previous;
        }
    }

    /**
     * 模型全局作用域入口（由 app/model/BaseModel::scopeDataScope 调用）。
     *
     * 与 Logic 层显式调用的区别只有「用户从哪来」：这里取当前请求的用户上下文，
     * 取不到就不作用域 —— 覆盖 CLI（定时任务 / 命令 / 迁移）、登录链路
     * （数据范围本身来自被查的这行数据）与公共路由三类场景。
     *
     * @param mixed      $query  模型查询对象
     * @param string     $table  模型对应表名（不含前缀）
     * @param array|bool $config 模型声明：false=不参与；数组=参与，可覆盖 owner / dept / no_baseline
     */
    public static function applyToModelQuery($query, string $table, array|bool $config): void
    {
        // 显式声明不参与（基础设施表：菜单 / 配置 / 字典 / 关联表…）
        if ($config === false) {
            return;
        }

        $user = self::currentUser();
        if ($user === null) {
            return;
        }

        // 表名以模型为准，避免声明与实际不符
        self::row($query, $user, array_merge($config, ['table' => $table]));
    }

    /**
     * 本次请求的 scope plan（惰性构建，一次请求只算一次）。
     *
     * @return array{
     *     tables:array<int,string>,
     *     roleScope:int,
     *     roleIds:array<int,int>,
     *     effectiveRoleIds:array<int,int>,
     *     postIds:array<int,int>,
     *     deptIds:array<int,int>,
     *     deptParents:array<int|string,int>,
     *     rules:array<int,array<string,mixed>>
     * }
     */
    private static function plan(UserContext $user): array
    {
        self::$plans ??= new WeakMap();
        if (isset(self::$plans[$user])) {
            return self::$plans[$user];
        }

        $roleIds = array_map('intval', Db::name('user_role')->where('user_id', $user->id)->column('role_id'));

        // 角色 data_scope 取并集（最宽松值最小者）；无角色 / 角色全禁用 → 退化为「仅本人」。
        //
        // **只按直连角色计算档位，不含祖先角色**（与下面 effectiveRoleIds 的差别是刻意的）：
        // 档位如果跟随祖先，子角色用户会因父角色「全部数据」而放大范围 —— 属于隐式扩权，
        // 与「新增子角色不影响父角色」的设计相反。角色的**权限节点**才走继承。
        $roleScope = 4;
        if ($roleIds !== []) {
            $scopes = SoftDelete::apply(Db::name('role'))
                ->whereIn('id', $roleIds)
                ->where('status', 1)
                ->column('data_scope');
            if ($scopes !== []) {
                $roleScope = (int)min(array_map('intval', $scopes));
            }
        }

        // 租户内的维度数据：角色 / 岗位 / 部门 / 规则都必须限定在本租户，
        // 否则规则页里会看到别的租户的绑定对象，全局规则还会跨租户命中。
        // 关联表（user_role / user_post / user_dept）按 user_id 取，本身就是本用户的。
        $tenantId = $user->tenantId;

        $plan = [
            'tables'      => self::queryGuardedTables($tenantId),
            'roleScope'   => $roleScope,
            'roleIds'     => $roleIds,
            // 有效角色 = 直连 + 祖先（与鉴权 AuthService::effectiveRoleIds 同口径）。
            // 规则的「绑定角色」与动态变量 {role.ids} 都用它，避免出现
            // 「按权限能进这个菜单、数据规则却匹配不上父角色」的割裂。
            'effectiveRoleIds' => AuthService::effectiveRoleIds($user->id),
            'postIds'     => array_map('intval', Db::name('user_post')->where('user_id', $user->id)->column('post_id')),
            'deptIds'     => array_map('intval', Db::name('user_dept')->where('user_id', $user->id)->column('dept_id')),
            // 部门父子映射只取本租户：子树计算不能顺着 parent_id 走进别的租户
            'deptParents' => SoftDelete::apply(Db::name('dept'))
                ->where('tenant_id', $tenantId)
                ->column('parent_id', 'id'),
            // 已进回收站的规则不能再生效；action / 目标表 / 绑定过滤在 rules() 里做（纯内存）。
            // 显式按 id 排序：顺序对行级规则（AND 叠加）没有影响，但字段级动作是**按顺序依次处理**的
            // （applyFieldRules 逐条 switch），不指定顺序时就取决于 DB 返回顺序 ——
            // 同一字段被多个字段级规则命中时，结果会变成不可复现。这里固定为创建顺序。
            'rules'       => SoftDelete::apply(Db::name('data_rule'))
                ->where('tenant_id', $tenantId)
                ->order('id', 'asc')
                ->select()
                ->toArray(),
        ];

        self::$plans[$user] = $plan;

        return $plan;
    }

    /** 该表是否登记为受控表（决定能不能配自定义规则、规则会不会生效） */
    public static function canGuard(string $table): bool
    {
        return in_array($table, self::guardedTables(), true);
    }

    /**
     * 行级：向查询注入 where。
     *
     * @param array{
     *     owner?: string|callable(mixed, UserContext): void,
     *     table?: string,
     *     dept?: callable(mixed, array<int>): void,
     *     no_baseline?: bool
     * } $options
     *   - owner：「仅本人」按哪个列判定，默认 `create_by`。
     *     系统表（如 sys_user）没有 create_by，应传 `'id'`（"仅本人"= 只看自己这个账号）；
     *     语义不是「某一列等于我」时（如部门表：仅本人 = 我所属的部门），传回调
     *     `callable($query, UserContext $user)`，自行 fail-closed。
     *   - table：当前查询的表（不含前缀），用于只取「不限表」或「指定了这张表」的规则。
     *   - dept：「本部门 / 及以下」如何落到当前表，回调收到的是**已展开好的**部门 id 列表。
     *     不传则默认 `whereIn('dept_id', $ids)`（适用于带 dept_id 的业务表）。
     *     回调需自行 fail-closed：列表为空时不能放行任何数据。
     *   - no_baseline：true 时**跳过** data_scope 的预设基线（仅本人 / 本部门），
     *     只叠加自定义行级规则。适用于没有 owner（create_by）与 dept_id 列的业务表
     *     （如插件表 ks_*），避免拼出不存在的列导致 SQL 报错；隔离完全由自定义规则表达。
     */
    public static function row($query, UserContext $user, array $options = []): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $plan  = self::plan($user);
        $scope = $plan['roleScope'];
        if ($scope === 1) {
            return;
        }

        // 业务表可能既无 create_by 也无 dept_id，此时只走自定义规则（见 docblock）
        $noBaseline = !empty($options['no_baseline']);

        if (!$noBaseline && $scope === 4) {
            $applyOwner = $options['owner'] ?? 'create_by';
            if (is_callable($applyOwner)) {
                $applyOwner($query, $user);
            } else {
                $query->where((string)$applyOwner, $user->id);
            }
        } elseif (!$noBaseline && in_array($scope, [2, 3], true)) {
            $ids = $plan['deptIds'];
            if ($scope === 2) {
                $ids = self::deptAndChildren($ids, $plan['deptParents']);
            }

            $applyDept = $options['dept'] ?? null;
            if (is_callable($applyDept)) {
                $applyDept($query, $ids);
            } elseif ($ids) {
                $query->whereIn('dept_id', $ids);
            } else {
                // fail-closed：声明了「本部门」却没有任何部门，不能退化成「不过滤」
                $query->whereIn('dept_id', [0]);
            }
        }

        // 自定义行级规则（action=row），在预设基线之上叠加
        $table = (string)($options['table'] ?? '');
        $rules = self::rules($user, 'row', $table);

        // 自定义档没有预设基线，完全依赖规则：一条都没命中就必须 fail-closed ——
        // 「自定义规则没填 = 没有任何权限」。否则「绑错对象 / 忘了配规则」会静默变成
        // 「看全部」，比不配还危险。
        //
        // 无条件成立（不再限定受控表）：模型参与数据权限就说明这张表按范围隔离，
        // 此时「没有规则」只能是「无权限」。若某张表压根不该隔离，正确做法是在模型上
        // 声明 `$dataScope = false`，而不是靠它没登记受控表来兜住 —— 那反而会
        // 留下「参与基线却没登记」的静默放行口子。
        if ($scope === 5 && $rules === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($rules as $rule) {
            self::applyRowRule($query, $rule, $user);
        }
    }

    /**
     * 应用一条行级规则。
     *
     * in / between 的取值在界面里用逗号分隔，这里拆成数组走 whereIn / whereBetween；
     * 否则 where(field, 'in', 'a,b') 会生成非法 SQL。
     *
     * value_type=dynamic 时，取值里的 `{变量}` 会先按当前用户解析成 id 集合
     * （可与字面量混用），因此 `dept_id in {dept.subtree}` 这类「动态范围」
     * 也能作为普通自定义规则叠加在 data_scope 基线上。
     *
     * @param array<string,mixed> $rule
     */
    private static function applyRowRule($query, array $rule, UserContext $user): void
    {
        $field     = (string)$rule['field'];
        $operator  = strtolower(trim((string)$rule['operator']));
        $raw       = (string)$rule['value'];
        $isDynamic = ((string)($rule['value_type'] ?? 'static')) === 'dynamic';

        // 静态取值保持原语义（含 `= ''` 这种边界），只有动态取值才需要解析变量
        $values = $isDynamic ? self::resolveValues($raw, $user) : [];

        if ($operator === 'in') {
            $items = $isDynamic
                ? $values
                : array_values(array_filter(
                    array_map('trim', explode(',', $raw)),
                    static fn ($item) => $item !== ''
                ));
            // fail-closed：解析后为空不能退化成「不过滤」
            $query->whereIn($field, $items ?: ['']);

            return;
        }

        if ($operator === 'between') {
            $parts = $isDynamic
                ? $values
                : array_map('trim', explode(',', $raw));
            if (count($parts) < 2) {
                return; // 取值不完整：宁可这条规则不生效，也不生成非法 SQL
            }
            $query->whereBetween($field, [$parts[0], $parts[1]]);

            return;
        }

        if (!in_array($operator, self::ROW_OPERATORS, true)) {
            return;
        }

        if (!$isDynamic) {
            $query->where($field, $operator, $raw);

            return;
        }

        // 动态变量没解析出任何值（如用户没有部门）→ 该条规则不生效
        if ($values === []) {
            return;
        }
        // 变量展开成多个值（{dept.subtree} 等）时，标量操作符按「属于」处理
        if (count($values) > 1) {
            $query->whereIn($field, $values);

            return;
        }

        $query->where($field, $operator, $values[0]);
    }

    /**
     * 解析动态取值：按逗号拆分，把 `{变量}` 展开为当前用户对应的 id 集合。
     *
     * 只有「整段就是一个占位符」才会被展开（`1,{dept.ids}` 这种混用也支持）。
     *
     * @return array<int,string>
     */
    private static function resolveValues(string $raw, UserContext $user): array
    {
        $out = [];
        foreach (array_map('trim', explode(',', $raw)) as $part) {
            if ($part === '') {
                continue;
            }
            if (isset(self::VALUE_VARS[$part])) {
                foreach (self::variableValues($part, $user) as $value) {
                    $out[] = (string)$value;
                }
                continue;
            }
            $out[] = $part;
        }

        return array_values(array_unique($out));
    }

    /**
     * 单个变量 → 当前用户对应的值集合。
     *
     * @return array<int,int|string>
     */
    private static function variableValues(string $var, UserContext $user): array
    {
        return match ($var) {
            '{user.id}'      => [$user->id],
            '{dept.ids}'     => self::userDeptIds($user),
            '{dept.subtree}' => self::userDeptSubtreeIds($user),
            '{post.ids}'     => self::userPostIds($user),
            '{role.ids}'     => self::userRoleIds($user),
            default          => [],
        };
    }

    /**
     * 出参字段级规则（模型层调用，见 `BaseModel::toArray()`）。
     *
     *   - hidden  → 不出参
     *   - mask    → 掩码（如 138****8888）
     *   - encrypt → 密文（前端解密）
     *   - readonly→ 出参照常，写入侧剔除（见 applyWriteRules）
     *
     * 「掩码值被表单原样回写」的问题由 `applyWriteRules()` 兜住，所以列表与单条详情
     * 可以用同一套出参语义，不需要再区分场景。
     *
     * @param array<string,mixed> $row
     * @param string              $table 当前查询的表（不含前缀）；用于筛掉指定了别的表的规则
     */
    public static function applyFieldRules(array &$row, string $table): void
    {
        $user = self::currentUser();
        if ($user === null || $user->isSuperAdmin()) {
            return;
        }

        try {
            $compiled = self::compileFieldRules(self::rules($user, 'field', $table));
        } catch (InvalidArgumentException $e) {
            // 编译失败 → 整行拒绝：无法确定损坏的规则原本要保护哪个字段，
            // 就不能挑「看起来没问题」的部分返回。
            $row = [];
            self::reportBrokenRule($e);

            return;
        }

        foreach ($compiled as $item) {
            $field = $item['field'];
            if (!array_key_exists($field, $row)) {
                continue;
            }
            switch ($item['action']) {
                case 'hidden':
                    unset($row[$field]);
                    break;
                case 'mask':
                    $row[$field] = self::mask((string)$row[$field], $field);
                    break;
                case 'encrypt':
                    // 未配置密钥时保持原样，避免用公开默认密钥「假装加密」
                    if (Cipher::configured()) {
                        $row[$field] = Cipher::encrypt((string)$row[$field]);
                    }
                    break;
                    // readonly：出参照常，仅入参剔除
            }
        }
    }

    /**
     * 把字段级规则编译成有序的「字段 + 动作」列表。
     *
     * 顺序必须保留：同一字段被多条规则命中时，`hidden` 会让字段从行里消失，
     * 后续规则自然不再处理它；折叠成 `field => action` 映射会改变这个语义。
     *
     * 编译失败（动作不在白名单、字段名为空或不是合法列名）直接抛异常，由调用方
     * **整条拒绝**：规则损坏时不能「跳过这条、其余照旧」—— 那等于把本该隐藏 /
     * 掩码的字段原样返回。保存侧（`DataRuleLogic::prepare()`）已做同样的校验，
     * 这里兜的是手改 SQL、旧版本数据、迁移异常。
     *
     * @param array<int,array<string,mixed>> $rules 已按目标表过滤后的字段级规则
     * @return list<array{field:string,action:string}>
     * @throws InvalidArgumentException
     */
    public static function compileFieldRules(array $rules): array
    {
        $compiled = [];

        foreach ($rules as $rule) {
            $field  = trim((string)($rule['field'] ?? ''));
            $action = strtolower(trim((string)($rule['action'] ?? '')));
            $id     = (int)($rule['id'] ?? 0);

            if ($field === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
                throw new InvalidArgumentException(
                    "字段规则 #{$id} 的字段名非法（" . ($field === '' ? '空' : $field) . '），已按 fail-closed 拒绝'
                );
            }

            if (!in_array($action, self::FIELD_ACTIONS, true)) {
                throw new InvalidArgumentException(
                    "字段规则 #{$id} 的动作非法（" . ($action === '' ? '空' : $action) . '），已按 fail-closed 拒绝'
                );
            }

            $compiled[] = ['field' => $field, 'action' => $action];
        }

        return $compiled;
    }

    /**
     * 入参字段级规则（模型层调用，见 `app/model/ScopedQuery.php`）。
     *
     * 剔除全部四种动作：
     *   - readonly：可见但不可改；
     *   - hidden / mask / encrypt：操作者拿不到真实值，若允许提交，掩码 / 密文 / 空值会写库。
     *
     * 依赖「当前请求用户」，所以只能在**执行期**（查询构造器真正写库前）取，
     * 无法像声明式配置那样在配置期生成。
     *
     * @param array<string,mixed> $data
     * @param string              $table 当前写入的表（不含前缀）
     */
    public static function applyWriteRules(array &$data, string $table): void
    {
        $user = self::currentUser();
        if ($user === null || $user->isSuperAdmin()) {
            return;
        }

        try {
            $compiled = self::compileFieldRules(self::rules($user, 'field', $table));
        } catch (InvalidArgumentException $e) {
            // 与出参同口径：规则不可信时拒绝这次写入，而不是按「部分规则」猜
            $data = [];
            self::reportBrokenRule($e);

            return;
        }

        foreach ($compiled as $item) {
            unset($data[$item['field']]);
        }
    }

    /**
     * 规则损坏时记一条日志（每请求最多一条，避免列表循环里刷屏）。
     *
     * 常驻进程下不能用静态变量节流（会跨请求泄漏），所以标记挂在 request 上；
     * CLI 没有 request 时直接写 —— 那里的数据量本来就不大。
     */
    private static function reportBrokenRule(InvalidArgumentException $e): void
    {
        $request = function_exists('request') ? request() : null;
        if ($request !== null) {
            if (!empty($request->dataScopeRuleBrokenLogged)) {
                return;
            }
            $request->dataScopeRuleBrokenLogged = true;
        }

        if (!function_exists('runtime_path')) {
            return;
        }

        @file_put_contents(
            runtime_path() . '/logs/data-scope.log',
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $e->getMessage()),
            FILE_APPEND
        );
    }

    /**
     * 读取适用的数据权限规则（从 scope plan 取，不再查库）。
     *
     * @param string|null $action 'row' 或 'field'（null=全部）
     * @param string      $table  当前查询的表（不含前缀）
     * @return array<int,array<string,mixed>>
     */
    private static function rules(UserContext $user, ?string $action, string $table = ''): array
    {
        $plan = self::plan($user);

        // 动作过滤（原先是 SQL where，现在数据已在内存）
        $all = $plan['rules'];
        if ($action === 'row') {
            $all = array_filter($all, static fn ($r): bool => (string)($r['action'] ?? '') === 'row');
        } elseif ($action === 'field') {
            $all = array_filter($all, static fn ($r): bool => (string)($r['action'] ?? '') !== 'row');
        }
        $all = array_values($all);

        // 目标表过滤：规则没指定表 = 对所有模块生效；指定了表的，只在该表上生效。
        // 调用方没声明自己的表时，指定了表的规则一律不用（避免把别的表的列拼进当前 SQL）。
        // 未接入数据权限的表即使被写了规则也不会生效（与「受控表」保持一致）。
        $guarded = $plan['tables'];
        $all     = array_values(array_filter(
            $all,
            static function ($r) use ($table, $guarded) {
                $bound = (string)($r['table_name'] ?? '');
                if ($bound === '') {
                    return true;
                }
                return $table !== '' && $bound === $table && in_array($bound, $guarded, true);
            }
        ));

        $postIds     = $plan['postIds'];
        // 角色绑定按「有效角色（直连 + 祖先）」判定，与鉴权口径一致
        $roleIds     = $plan['effectiveRoleIds'];
        $deptIds     = $plan['deptIds'];
        // 部门绑定按「含下级」处理：绑「总公司」要覆盖「研发部」的人。
        // 部门树在 plan 里只取一次，规则再多也不重复查。
        $deptParents = $plan['deptParents'];

        return array_values(array_filter(
            $all,
            function ($r) use ($user, $postIds, $roleIds, $deptIds, $deptParents) {
                $dept = json_decode((string)($r['dept_ids'] ?? 'null'), true);
                $dept = is_array($dept) ? array_map('intval', $dept) : [];

                // 逐维度判定，只收集**已填写**的维度：未填写 = 不限，不参与判断。
                // 若把未填写也算作「不命中」，只绑一项的 and 规则将永不生效。
                $hits = [];
                if ((int)$r['user_id'] > 0) {
                    $hits[] = (int)$r['user_id'] === $user->id;
                }
                if ((int)$r['post_id'] > 0) {
                    $hits[] = in_array((int)$r['post_id'], $postIds, true);
                }
                if ((int)$r['role_id'] > 0) {
                    $hits[] = in_array((int)$r['role_id'], $roleIds, true);
                }
                if ($dept !== []) {
                    // 绑定部门展开成子树后再与「用户所属部门」求交集
                    $hits[] = count(array_intersect(self::deptAndChildren($dept, $deptParents), $deptIds)) > 0;
                }

                if ($hits === []) {
                    return true; // 无绑定 = 全局规则
                }

                // and：所有已填写的维度都命中；or：命中任一即可。
                // 列是 NOT NULL DEFAULT 'or'，非法值只可能来自手改 SQL，一律按 or 处理。
                $mode = strtolower(trim((string)$r['bind_mode']));
                if (!in_array($mode, self::BIND_MODES, true)) {
                    $mode = 'or';
                }

                return $mode === 'and' ? !in_array(false, $hits, true) : in_array(true, $hits, true);
            }
        ));
    }

    /** 我的角色（取 plan，请求内只查一次）；用「有效角色（直连 + 祖先）」，与鉴权同口径 */
    private static function userRoleIds(UserContext $user): array
    {
        return self::plan($user)['effectiveRoleIds'];
    }

    /** 我的岗位（取 plan，请求内只查一次） */
    private static function userPostIds(UserContext $user): array
    {
        return self::plan($user)['postIds'];
    }

    /** 我所属部门（取 plan，请求内只查一次） */
    private static function userDeptIds(UserContext $user): array
    {
        return self::plan($user)['deptIds'];
    }

    /** 我所属部门及其所有下级（取 plan 的部门树，不再重复查库） */
    private static function userDeptSubtreeIds(UserContext $user): array
    {
        $plan = self::plan($user);

        return self::deptAndChildren($plan['deptIds'], $plan['deptParents']);
    }

    /**
     * 我所属部门（不含下级）。
     *
     * 供模型声明自定义 owner / dept 落地方式时复用（如部门表：「仅本人」= 我所属的部门），
     * 与行级规则的动态变量 `{dept.ids}` 取值一致。
     *
     * @return array<int,int>
     */
    public static function myDeptIds(UserContext $user): array
    {
        return self::userDeptIds($user);
    }

    /**
     * 我所属部门及其所有下级，与动态变量 `{dept.subtree}` 取值一致。
     *
     * @return array<int,int>
     */
    public static function myDeptSubtreeIds(UserContext $user): array
    {
        return self::userDeptSubtreeIds($user);
    }

    /**
     * 部门子树展开：给定部门 + 其所有下级部门。
     *
     * @param array<int,int>             $ids
     * @param array<int|string,int>|null $parents id => parent_id；批量调用时可传入复用，省一次查询
     */
    private static function deptAndChildren(array $ids, ?array $parents = null): array
    {
        $parents ??= SoftDelete::apply(TenantContext::table('dept'))->column('parent_id', 'id');

        return Tree::subtreeIds($parents, $ids);
    }

    private static function mask(string $value, string $field): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($field, 'phone') || str_contains($field, 'mobile')) {
            return substr($value, 0, 3) . '****' . substr($value, -4);
        }
        if (str_contains($field, 'id_card') || str_contains($field, 'idcard')) {
            return substr($value, 0, 4) . '**********' . substr($value, -4);
        }
        $len = strlen($value);
        if ($len <= 2) {
            return $value;
        }
        $show = (int)floor($len / 3);
        return substr($value, 0, $show) . str_repeat('*', $len - 2 * $show) . substr($value, -$show);
    }
}
