<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Crontab;
use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\File;
use plugin\cccms\app\model\Menu;
use plugin\cccms\app\model\RoleNode;
use plugin\cccms\app\model\User;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\DictCache;
use plugin\cccms\support\FileStorage;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PermissionCache;
use plugin\cccms\support\SoftDelete;
use plugin\cccms\support\TenantContext;
use think\facade\Db;

/**
 * 回收站：统一管理各模块「软删除」数据的**恢复 / 彻底删除**。
 *
 * 列表刻意不在这里做：各模块页面点「回收站」按钮后，用的是**自己的列表接口 + `trashed=1`**
 * （见 `SoftDelete::listQuery`），所以同一张表、同一套列，只是换了数据源。
 * 这里只负责两个写操作：按 `type` 找到表，并做恢复前的约束校验。
 *
 * 恢复约束：
 *   - 带唯一键的表（user.username / role.code / post.code / dict_type.type）
 *     若唯一值已被新数据占用，恢复会失败，这里提前给出可读提示；
 *   - 部门 / 分类这类树结构，上级还在回收站时不允许恢复，避免出现「挂在已删节点下」的孤儿。
 */
final class RecycleLogic
{
    /** 类型 => [表名, 中文名, 名称列, 次要列(可为空)] */
    private const TYPES = [
        'user'      => ['table' => 'user',      'label' => '用户',        'name' => 'username',      'sub' => 'nickname'],
        'role'      => ['table' => 'role',      'label' => '角色',        'name' => 'name',          'sub' => 'code'],
        'dept'      => ['table' => 'dept',      'label' => '部门',        'name' => 'name',          'sub' => ''],
        'post'      => ['table' => 'post',      'label' => '岗位',        'name' => 'name',          'sub' => 'code'],
        'dict_type' => ['table' => 'dict_type', 'label' => '字典类型',    'name' => 'name',          'sub' => 'type'],
        'dict_data' => ['table' => 'dict_data', 'label' => '字典数据',    'name' => 'label',         'sub' => 'value'],
        'category'  => ['table' => 'category',  'label' => '分类',        'name' => 'name',          'sub' => 'module'],
        'crontab'   => ['table' => 'crontab',   'label' => '定时任务',    'name' => 'name',          'sub' => 'expression'],
        'data_rule' => ['table' => 'data_rule', 'label' => '数据权限规则', 'name' => 'name',         'sub' => 'field'],
        'file'      => ['table' => 'file',      'label' => '附件',        'name' => 'original_name', 'sub' => 'ext'],
        'menu'      => ['table' => 'menu',      'label' => '菜单/权限节点', 'name' => 'title',        'sub' => 'node'],
        'notice'    => ['table' => 'notice',    'label' => '通知公告',    'name' => 'title',         'sub' => 'type'],
    ];

    /** 带唯一键的表：恢复前要检查唯一值是否已被占用 */
    private const UNIQUE_COLUMN = [
        'user'      => 'username',
        'role'      => 'code',
        'post'      => 'code',
        'dict_type' => 'type',
        'menu'      => 'node',
    ];

    /** 有上级的类型：恢复前要检查上级是否还在回收站，避免出现「挂在已删节点下」的孤儿 */
    private const PARENTS = [
        'dept'      => ['column' => 'parent_id', 'table' => 'dept'],
        'category'  => ['column' => 'parent_id', 'table' => 'category'],
        'dict_data' => ['column' => 'type_id',   'table' => 'dict_type'],
        'menu'      => ['column' => 'parent_id', 'table' => 'menu'],
    ];

    /**
     * 参与数据权限的类型 => 模型类。
     *
     * 恢复 / 彻底删除是**写操作**，必须与列表同一口径：数据档位（仅本人 / 本部门 /
     * 自定义规则）在回收站同样生效，否则持有回收站权限、但范围被收窄的账号可以枚举
     * id 去恢复 / 彻底删除同租户内自己看不见的数据（越权写）。
     *
     * 其余类型在各自模型里声明 `$dataScope = false`（组织架构 / 基础数据只按权限节点
     * 控制，不按人收窄），因此不需要额外过滤 —— 租户边界已由 `self::query()` 保证。
     */
    private const SCOPED_MODEL = [
        'user'    => User::class,
        'dept'    => Dept::class,
        'file'    => File::class,
        'crontab' => Crontab::class,
    ];

    /** 业务插件声明的回收站类型缓存（plugin/{插件}/db/recycle.php） */
    private static ?array $pluginTypes = null;

    /**
     * 业务插件声明的回收站类型。
     *
     * 与 `db/menu.php` 同一约定：插件在 `db/recycle.php` 里声明
     * `['ks_subject' => ['table' => 'ks_subject', 'label' => '主体', 'name' => 'name', 'sub' => 'credit_code']]`。
     *
     * 这类表的表名是**完整表名**（如 `ks_*`，不走全局 `sys_` 前缀），
     * 因此取查询构造器时走 `Db::table()` 而非 `Db::name()`。
     *
     * @return array<string,array{table:string,label:string,name:string,sub:string,full:bool}>
     */
    private static function pluginTypes(): array
    {
        if (self::$pluginTypes !== null) {
            return self::$pluginTypes;
        }

        $types = [];
        foreach (glob(base_path() . '/plugin/*/db/recycle.php') ?: [] as $file) {
            $declared = (array)(include $file);
            foreach ($declared as $type => $meta) {
                if (!is_array($meta) || empty($meta['table'])) {
                    continue;
                }
                $types[(string)$type] = [
                    'table' => (string)$meta['table'],
                    'label' => (string)($meta['label'] ?? $type),
                    'name'  => (string)($meta['name'] ?? 'name'),
                    'sub'   => (string)($meta['sub'] ?? ''),
                    'full'  => true,
                ];
            }
        }

        return self::$pluginTypes = $types;
    }

    /** 按类型取查询构造器：内置表走前缀（Db::name），插件表走完整表名（Db::table） */
    private static function query(array $meta)
    {
        return self::rawQuery($meta, true);
    }

    /**
     * @param bool $tenantScoped true = 收敛到当前租户（业务操作）；
     *                           false = 全局（唯一键占用判断必须看全量，见 assertRestorable ①）
     */
    private static function rawQuery(array $meta, bool $tenantScoped)
    {
        $query = !empty($meta['full']) ? Db::table($meta['table']) : Db::name($meta['table']);

        // 回收站同样是租户硬边界：A 租户不能恢复 / 彻底删除 B 租户的行，
        // 也不能把「别的租户已占用的唯一值」当成没占用。插件表未参与隔离，applyToQuery 自动跳过。
        if ($tenantScoped) {
            TenantContext::applyToQuery($query, (string)$meta['table']);
        }

        return $query;
    }

    public static function restore(string $type, array $ids): int
    {
        $meta = self::meta($type);
        $ids  = self::ids($ids);
        $ids  = self::visibleIds($type, $ids);
        if ($ids === []) {
            return 0;
        }

        self::assertRestorable($type, $meta, $ids);
        self::assertNoDuplicateValues($type, $meta, $ids);

        $affected = SoftDelete::restore(self::query($meta), $ids);

        // 恢复的可能是角色 / 菜单 / 用户：权限集合要立即重算
        PermissionCache::bump();
        // 恢复的可能是字典类型 / 字典数据：字典缓存要立即失效
        if ($type === 'dict_type' || $type === 'dict_data') {
            DictCache::bump();
        }

        return $affected;
    }

    /**
     * 字典数据恢复前的「值重复」校验。
     *
     * 场景：值 `1` 先进回收站，随后有人新建了同类型同值的 `1`；
     * 此时恢复旧行会出现两条 `type_id + value` 相同的记录 —— 下拉框同值多项、回显不确定。
     * 库上没有 `(type_id, value)` 唯一索引（软删 + 唯一键会让「删了再建」失败），
     * 因此把校验放在恢复入口。
     */
    private static function assertNoDuplicateValues(string $type, array $meta, array $ids): void
    {
        if ($type !== 'dict_data') {
            return;
        }

        $rows = SoftDelete::onlyTrashed(self::query($meta))
            ->whereIn('id', $ids)
            ->field('id,type_id,value')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $value = (string)($row['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $exists = SoftDelete::apply(self::query($meta))
                ->where('type_id', (int)($row['type_id'] ?? 0))
                ->where('value', $value)
                ->find();
            if ($exists) {
                throw new ApiException(I18n::t('dict.data_value_exists', ['value' => $value]), 422);
            }
        }
    }

    /** 彻底删除（附件连同物理文件一起删） */
    public static function forceDelete(string $type, array $ids): int
    {
        $meta = self::meta($type);
        $ids  = self::ids($ids);
        $ids  = self::visibleIds($type, $ids);
        if ($ids === []) {
            return 0;
        }

        if ($type === 'file') {
            // 软删阶段刻意保留物理文件，只有「彻底删除」才真正落盘删除。
            // withTrashed()：要读的正是回收站里的行，模型默认会排除它们
            $paths = File::withTrashed()->whereIn('id', $ids)->column('path');
            foreach ($paths as $path) {
                FileStorage::delete((string)$path);
            }
        }

        if ($type === 'menu') {
            // 软删阶段刻意保留 role_node 授权（便于恢复）；彻底删除时一并清掉，避免残留孤儿授权。
            // 一次 whereIn 删除：逐条 DELETE 在节点多时会产生上百次往返，且没有事务保护
            $nodes = array_values(array_filter(
                array_map('strval', Menu::onlyTrashed()->whereIn('id', $ids)->column('node')),
                static fn (string $node): bool => $node !== ''
            ));
            if ($nodes !== []) {
                RoleNode::whereIn('node', $nodes)->delete();
            }
        }

        if ($type === 'notice') {
            // 彻底删除公告时一并清理定向投放目标与已读记录，避免残留孤儿
            Db::name('notice_target')->whereIn('notice_id', $ids)->delete();
            Db::name('notice_read')->whereIn('notice_id', $ids)->delete();
        }

        $affected = SoftDelete::force(self::query($meta), $ids);

        // 彻底删除可能清掉了 role_node 授权，或让「回收站里的同名角色」不再占用标识
        PermissionCache::bump();
        if ($type === 'dict_type' || $type === 'dict_data') {
            DictCache::bump();
        }

        return $affected;
    }

    /** 恢复前的约束检查 */
    private static function assertRestorable(string $type, array $meta, array $ids): void
    {
        // ① 唯一值是否被新数据占用
        $unique = self::UNIQUE_COLUMN[$type] ?? null;
        if ($unique !== null) {
            $values = SoftDelete::onlyTrashed(self::query($meta))
                ->whereIn('id', $ids)
                ->column($unique);
            foreach ($values as $value) {
                // 必须是「活着的行」里查重：待恢复的这行本身也在库里，
                // 不过滤已删数据的话会把自己算成「已被占用」，恢复永远失败。
                // 且**必须全局查重**：username / code / type / node 都是全局唯一索引，
                // 只看本租户会把「别的租户已占用」判成可用，恢复时撞唯一键。
                $taken = SoftDelete::apply(self::rawQuery($meta, false))->where($unique, $value)->count();
                if ($taken > 0) {
                    throw new ApiException(I18n::t('recycle.value_taken', ['value' => $value]), 422);
                }
            }
        }

        // ② 上级还在回收站时不允许恢复
        $parent = self::PARENTS[$type] ?? null;
        if ($parent !== null) {
            $parentIds = array_values(array_filter(array_map(
                'intval',
                SoftDelete::onlyTrashed(self::query($meta))->whereIn('id', $ids)->column($parent['column'])
            )));
            if ($parentIds !== []) {
                $trashed = SoftDelete::onlyTrashed(TenantContext::table((string)$parent['table']))->whereIn('id', $parentIds)->count();
                if ($trashed > 0) {
                    throw new ApiException(I18n::t('recycle.parent_trashed'), 422);
                }
            }
        }
    }

    /** @return array{table:string,label:string,name:string,sub:string,full:bool} */
    private static function meta(string $type): array
    {
        if (isset(self::TYPES[$type])) {
            return self::TYPES[$type] + ['full' => false];
        }

        $plugin = self::pluginTypes();
        if (isset($plugin[$type])) {
            return $plugin[$type];
        }

        throw new ApiException(I18n::t('recycle.unknown_type', ['type' => $type]), 422);
    }

    /**
     * 收敛到「当前用户数据范围内可见」的已删 id（参与数据权限的类型）。
     *
     * 模型 `onlyTrashed()` 会带上租户 + 数据档位两个全局作用域，再叠加传入的 id 集合，
     * 因此返回的就是「当前用户既有回收站权限、又落在自己数据范围内」的已删行。
     * 未登记的 id 被静默丢弃 —— 与各模块批量删除的「范围外跳过」同口径。
     *
     * @param  int[] $ids
     * @return int[]
     */
    private static function visibleIds(string $type, array $ids): array
    {
        $model = self::SCOPED_MODEL[$type] ?? null;
        if ($model === null) {
            return $ids;
        }

        return array_map('intval', $model::onlyTrashed()->whereIn('id', $ids)->column('id'));
    }

    /** @return int[] */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if ($ids === []) {
            throw new ApiException(I18n::t('common.select_required'), 422);
        }

        return $ids;
    }
}
