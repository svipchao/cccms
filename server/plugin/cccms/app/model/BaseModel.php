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
 * 模型基类（表名省略 sys_ 前缀，由连接配置补全）。
 *
 * 数据权限默认接入：查询与单条读写都会自动带上当前用户的数据范围，业务层不用记得
 * 调 `DataScope::row()`。参与方式由 `$dataScope` 声明：false 不参与；数组可覆盖
 * owner / dept / no_baseline；不声明则按 create_by / dept_id 走基线——声明了却没有
 * 这两列会直接报「列不存在」，逼你把这张表的数据范围写清楚，而不是静默放行。
 * 预设基线对所有参与模型生效，自定义规则只对登记在 `sys_data_scope_table` 的表生效。
 *
 * 租户是比档位更高一层的硬边界（超管也受约束），因此独立成 `tenant` 作用域并排在
 * `dataScope` 之前；模型用 `$tenantScope = true` 显式声明（默认 false），只有真带
 * tenant_id 的业务表参与，升级时不会波及存量表。
 *
 * 逃生口：`withoutGlobalScope()` 只解除档位、保留租户（唯一性/存在性校验用）；
 * 真正跨租户（登录、CLI、迁移、全局唯一性）必须显式 `withoutAllScopes()`。
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
     * 放在 `toArray()` 里，列表、详情、导出等所有出参路径自动生效；写入侧由
     * `ScopedQuery` 负责剔除不可写字段，
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
