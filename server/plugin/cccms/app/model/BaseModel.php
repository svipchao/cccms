<?php

declare(strict_types=1);

namespace plugin\cccms\app\model;

use InvalidArgumentException;
use plugin\cccms\support\DataScope;
use plugin\cccms\support\TenantContext;
use think\db\BaseQuery;
use think\Model;
use think\model\concern\SoftDelete;

/**
 * 模型基类。表名统一省略 sys_ 前缀（连接配置 prefix 自动补全）。
 *
 * 数据权限：
 *   所有模型**默认接入**（`$globalScope`），查询会自动带上当前用户的数据范围，
 *   业务层不需要记得调用 `DataScope::row()` —— 单条读写入口（详情 / 编辑 / 删除）
 *   同样被覆盖，不会出现「列表过滤了、按 id 直调却越权」的漏网。
 *
 *   参与方式由模型自己声明 `$dataScope`：
 *     - `false`：不参与（基础设施表，如 sys_menu / sys_config / 字典 / 关联表）；
 *     - 数组：参与，可覆盖 `owner`（「仅本人」按哪列）/ `dept`（「本部门」如何落到本表）
 *       / `no_baseline`（表里没有 owner 与部门列，隔离完全由自定义规则表达）；
 *     - 不声明（默认 `[]`）：按 `create_by` / `dept_id` 走基线。
 *
 *   不声明又确实没有这两列的表，查询会直接报「列不存在」—— 这是**有意的**：
 *   逼你把「这张表的数据范围怎么算」写清楚，而不是静默放行。
 *
 *   生效条件（缺一不可）：
 *     1. 模型参与（`$dataScope !== false`）；
 *     2. 有当前用户（CLI / 定时任务 / 登录链路 / 公共路由没有用户，自动跳过）；
 *     3. 非超管、且角色不是「全部数据」。
 *   预设基线对所有参与的模型生效；**自定义规则**只对登记在 `sys_data_scope_table`
 *   的受控表生效（登记入口见数据权限规则页的「受控表」）。
 *
 *   逃生口：`User::withoutGlobalScope()->where(...)`（think-orm 自带），
 *   用于登录链路、CLI、以及「必须读到全量数据」的场景（如唯一性校验）。
 *
 * 多租户（P2-17）：
 *   租户是**硬边界**，与数据档位是两个层级 —— 数据档位在租户内部再收窄，
 *   而租户过滤连超管都受约束（超管跨租户必须显式「切换租户」）。
 *   因此租户条件**不放进** `DataScope::row()`，而是独立的作用域 `tenant`
 *   （`scopeTenant` → `TenantContext`），并且必须排在 `dataScope` **前面**：
 *   先圈定租户，再在租户内部按档位收窄，语义才不会反。
 *
 *   参与与否由模型显式声明 `$tenantScope = true`（**默认 false**）：
 *   只有真的带 `tenant_id` 列的业务表才参与 —— 基础设施表、关联表、
 *   以及 GeneratorLogic 产出的插件表（`ks_*`）都没有这一列，声明了会拼出
 *   不存在的列直接报错。刻意做成 opt-in 而不是 opt-out，升级时不会波及存量表。
 *
 *   逃生口做了收窄（见本类 `withoutGlobalScope` / `withoutAllScopes`）：
 *   `withoutGlobalScope()` 只解除**数据档位**、**保留租户**，因为项目里约 80 处
 *   调用都是「唯一性 / 存在性校验要看全量」这类需求，它们要的是跳出档位而不是
 *   跳出租户；真正需要跨租户（登录链路、CLI、迁移、全局唯一性）的少数几处
 *   必须显式用 `withoutAllScopes()`，让「我确实要跨租户」在代码里看得见。
 */
abstract class BaseModel extends Model
{
    /**
     * 软删除（think-orm 自带轨迹）。
     *
     * 只要模型对应表有 `delete_time`（默认名），查询就会**自动排除已删数据**，
     * 业务层不需要再记得调用 `SoftDelete::apply()`；回收站视图用 `Model::onlyTrashed()`，
     * 需要连已删数据一起操作（如恢复、彻底删除）用 `Model::withTrashed()`。
     *
     * 表里没有 `delete_time` 的模型必须显式声明 `protected $deleteTime = false;`，
     * 否则会拼出不存在的列 —— 这类表已在各自模型里声明。
     */
    use SoftDelete;

    protected $pk = 'id';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    /**
     * 全局查询作用域：租户（硬边界）在前，数据权限（租户内部软范围）在后。
     *
     * 顺序有意义：先圈定租户，档位条件才会落在租户内部。
     */
    protected $globalScope = ['tenant', 'dataScope'];

    /** 自定义查询类：写入时剔除当前用户不可写的字段（见 ScopedQuery） */
    protected $query = ScopedQuery::class;

    /**
     * 数据权限声明。
     *
     * false                         → 不参与（基础设施表）
     * ['owner' => 'id']             → 参与，覆盖「仅本人」判定列
     * ['dept'  => [self::class, 'deptScope']] → 参与，自定义「本部门」落地方式
     * ['no_baseline' => true]       → 参与，但不加预设基线（仅自定义规则生效）
     *
     * @var array<string,mixed>|bool
     */
    protected $dataScope = [];

    /**
     * 是否参与多租户隔离（默认 false = 不参与，显式 opt-in）。
     *
     * 只有**真的带 `tenant_id` 列**的业务表才声明 true：
     * 基础设施表（menu / config / log）、关联表（user_role / role_node …）
     * 以及插件产出的表（`ks_*`）都没有这一列，声明了会拼出不存在的列。
     *
     * @var bool
     */
    protected $tenantScope = false;

    /** 该模型对应表是否参与租户隔离（写入侧与作用域都用它判断） */
    public function participatesInTenant(): bool
    {
        return $this->tenantScope === true;
    }

    /**
     * 租户作用域：给查询注入 `tenant_id = 当前租户`。
     *
     * 无当前用户时（CLI / 登录链路 / 公共路由）自动跳过 —— 平台级任务需要跨租户处理数据。
     */
    public function scopeTenant($query): void
    {
        TenantContext::applyToModelQuery($query, $this->participatesInTenant());
    }

    /**
     * 全局作用域回调：think-orm 构造查询时按 `$globalScope` 里的名字挂载。
     *
     * 方法名必须是 `scope` + 声明名（`dataScope` → `scopeDataScope`），且为 public
     * （框架用 call_user_func_array 调用）。参数固定为当前查询对象。
     *
     * @param mixed $query 模型查询对象
     */
    public function scopeDataScope($query): void
    {
        DataScope::applyToModelQuery($query, $this->getName(), $this->dataScope);
    }

    /**
     * 出参统一过滤：字段级数据权限规则（`hidden` / `mask` / `encrypt`）。
     *
     * 放在 `toArray()` 里，列表、详情、导出等所有出参路径自动生效，业务层不需要
     * 记得调用 `DataScope::field()`；写入侧由 `ScopedQuery` 负责剔除不可写字段，
     * 所以「掩码值被表单原样提交回库」不会发生。
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        DataScope::applyFieldRules($data, $this->getName());

        return $data;
    }

    /**
     * 起始一个**带数据权限作用域**的查询对象。
     *
     * 静态魔术调用（`User::where(...)`）同样会带上作用域，正常业务直接用它；
     * 这里用于「先拿到 Query 再交给工具处理」的场景。
     */
    public static function newScopedQuery(): BaseQuery
    {
        return (new static())->db();
    }

    /**
     * 跳出全局作用域（**租户始终保留**）。
     *
     * think-orm 原语义是「把 `$scope` 里列出的作用域去掉」，本项目收窄为：
     * 无论传什么，`tenant` 都不会被去掉；不传参数时默认只去掉 `dataScope`。
     *
     * 这么做的原因：项目里有约 80 处 `withoutGlobalScope()` 调用，语义清一色是
     * 「唯一性 / 存在性校验必须看全量，不能因为看不见就当成没重复」——它们要跳出的是
     * **数据档位**，而不是租户。若沿用原语义（去掉全部作用域），这些调用点会集体
     * 变成跨租户读取，租户隔离被静默绕过。真正需要跨租户的少数几处必须显式声明
     * 意图，用下面的 `withoutAllScopes()`。
     *
     * @param string[]|null $scope 要去掉的作用域；`tenant` 会被自动忽略
     */
    public static function withoutGlobalScope(?array $scope = null): BaseQuery
    {
        $scope = $scope === null ? ['dataScope'] : array_values(array_diff($scope, ['tenant']));

        return (new static())->db($scope);
    }

    /**
     * 完全绕过**全部**全局作用域（含租户与软删除过滤之外的档位）。
     *
     * 只允许三类场景使用，且必须在调用处写清楚为什么：
     *   1. 登录 / 找回密码等**尚未建立用户上下文**的链路（数据范围本身要靠这行数据算）；
     *   2. CLI、命令、迁移、定时任务等平台级处理；
     *   3. **全局唯一性**判定（用户名 / 角色标识这类跨租户共用的唯一键）。
     *
     * 反例（不要这样写）：为了「看全量用户做存在性校验」而用它 —— 那属于第 1 类之外的
     * 普通业务读取，应该用 `withoutGlobalScope()`（保留租户）。
     *
     * `$reason` 是**必填**的：跨租户读取是最高危的一类操作，调用处必须用一句话说清
     * 「为什么这里可以跨租户」。评审时一眼能看到；空串直接抛异常，避免被当成默认选项。
     */
    public static function withoutAllScopes(string $reason): BaseQuery
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'withoutAllScopes() 必须说明跨租户理由（登录链路 / CLI / 全局唯一性），不能留空'
            );
        }

        $model = new static();
        $model->setOption('globalScope', []);

        return $model->db();
    }
}
