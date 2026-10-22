<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\DataRule;
use plugin\cccms\app\model\DataScopeTable;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use think\facade\Db;

/**
 * 数据权限「受控表」逻辑（sys_data_scope_table）。
 *
 * 这里是一份**登记表**：列进来的表才会出现在规则页的「目标表」候选里，
 * 其余系统表 / 业务表一律隐藏，避免配出一条永远不会生效的规则。
 *
 * 登记的前提是**该表已接入数据权限**：模型声明参与（`BaseModel::$dataScope`，
 * 预设基线即由此生效）且业务查询已走模型。`cccms:data-scope-check` 会校验这一点。
 *
 * 注意「登记」只决定**能不能配自定义规则**，不代表该表有没有数据权限：
 * `sys_dept` 就没有登记（不能配规则），但仍参与预设基线 ——
 * 「本部门及以下」档下部门管理页只见自己子树。
 *
 * 语义名优先取登记时手填的 label，留空则回退到库表注释。
 */
final class DataScopeTableLogic
{
    /**
     * 可写字段白名单（防 Mass Assignment）。
     *
     * `table_name` 会被校验为「必须存在于当前库」，但仍走白名单，
     * 避免客户端顺手塞 `id` / `tenant_id` / `create_time`。
     */
    private const FIELDS = ['table_name', 'label', 'status', 'remark'];

    /** 受控表列表 + 可添加的表（未登记的库表） */
    public static function index(): array
    {
        $rows   = DataScopeTable::newScopedQuery()->order('id', 'asc')->select()->toArray();
        $tables = self::dbTables();

        $list = [];
        foreach ($rows as $row) {
            $name = (string)$row['table_name'];
            $meta = $tables[$name] ?? null;

            // 语义名：手填优先，其次库表注释，最后表名
            $label = (string)$row['label'];
            if ($label === '') {
                $label = (string)($meta['comment'] ?? '') ?: $name;
            }

            $list[] = [
                'id'          => (int)$row['id'],
                'table_name'  => $name,
                'label'       => $label,
                'status'      => (int)$row['status'],
                'remark'      => (string)$row['remark'],
                'field_count' => (int)($meta['field_count'] ?? 0),
                // 表被删 / 改名后要能看出来，否则规则会「莫名不生效」
                'exists'      => $meta !== null,
            ];
        }

        $used      = array_map(static fn ($name) => (string)$name, DataScopeTable::withoutAllScopes('受控表名单是全局唯一索引，候选列表要跨租户排重')->column('table_name'));
        $available = [];
        foreach ($tables as $bare => $meta) {
            if (in_array($bare, $used, true)) {
                continue;
            }
            $available[] = [
                'table' => $bare,
                'full'  => $meta['full'],
                'label' => $meta['comment'] !== '' ? $meta['comment'] : $bare,
            ];
        }

        return ['list' => $list, 'available' => $available];
    }

    public static function save(array $data): int
    {
        $data = FilterInput::only($data, self::FIELDS);

        $table  = trim((string)($data['table_name'] ?? ''));
        $tables = self::dbTables();

        if ($table === '' || !isset($tables[$table])) {
            throw new ApiException(I18n::t('data_scope_table.table_not_found', [
                'table' => $table === '' ? I18n::t('data_scope_table.empty_table') : $table,
            ]), 422);
        }
        // `uk_table` 是**全局唯一索引**：受控表名单不随租户重复登记，
        // 因此查重必须绕过租户作用域，否则会撞唯一键（列表候选也据此排除，见 index()）。
        if (DataScopeTable::withoutAllScopes('uk_table 是全局唯一索引，跨租户查重才不会撞索引')->where('table_name', $table)->count() > 0) {
            throw new ApiException(I18n::t('data_scope_table.table_exists'), 422);
        }

        return (int)DataScopeTable::withoutGlobalScope()->insertGetId([
            'table_name'  => $table,
            'label'       => self::label($data['label'] ?? ''),
            'status'      => self::status($data['status'] ?? 1),
            'remark'      => trim((string)($data['remark'] ?? '')),
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::FIELDS);

        self::assertExists($id);

        $update = [];
        if (array_key_exists('label', $data)) {
            $update['label'] = self::label($data['label']);
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = self::status($data['status']);
        }
        if (array_key_exists('remark', $data)) {
            $update['remark'] = trim((string)$data['remark']);
        }
        if ($update === []) {
            return;
        }

        $update['update_time'] = date('Y-m-d H:i:s');
        DataScopeTable::where('id', $id)->update($update);
    }

    /**
     * 移除受控表。
     *
     * 规则本身不删：重新登记后即可恢复生效。返回该表上已有的规则数，
     * 让界面能明确提示「这些规则已暂停生效」。
     */
    public static function delete(int $id): int
    {
        $row = self::assertExists($id);

        $rules = (int)DataRule::withoutGlobalScope()->where('table_name', (string)$row['table_name'])->count();
        DataScopeTable::where('id', $id)->delete();

        return $rules;
    }

    /**
     * 库中所有表（标识 => 元信息）。
     *
     * 标识规则：
     *   - 带全局前缀的表（sys_*）：标识 = 去掉前缀的名字（保持既有语义）；
     *   - 不带前缀的表（业务插件表，如 ks_*）：标识 = **完整表名**。
     *
     * 业务插件（如 plugin/kuaishou 的 ks_* 表，连接前缀不适用）由此可纳入数据权限，
     * 规则只需把 `table_name` 写成完整表名，Logic 层 `DataScope::row(..., ['table' => 'ks_xxx'])` 即可命中。
     */
    private static function dbTables(): array
    {
        $prefix = (string)Db::connect()->getConfig('prefix');
        $rows   = Db::query(
            'SELECT t.TABLE_NAME, t.TABLE_COMMENT, COUNT(c.COLUMN_NAME) AS field_count
               FROM information_schema.TABLES t
               LEFT JOIN information_schema.COLUMNS c
                 ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
              WHERE t.TABLE_SCHEMA = DATABASE()
              GROUP BY t.TABLE_NAME, t.TABLE_COMMENT
              ORDER BY t.TABLE_NAME'
        );

        $out = [];
        foreach ($rows as $row) {
            $full = (string)$row['TABLE_NAME'];
            $hasPrefix = $prefix !== '' && str_starts_with($full, $prefix);
            $bare = $hasPrefix ? substr($full, strlen($prefix)) : $full;
            if ($bare === '') {
                continue;
            }
            $out[$bare] = [
                'table'       => $bare,
                'full'        => $full,
                'comment'     => trim((string)$row['TABLE_COMMENT']),
                'field_count' => (int)$row['field_count'],
                'has_prefix'  => $hasPrefix,
            ];
        }

        return $out;
    }

    private static function label(mixed $value): string
    {
        return mb_substr(trim((string)$value), 0, 64);
    }

    private static function status(mixed $value): int
    {
        return (int)$value === 1 ? 1 : 0;
    }

    private static function assertExists(int $id): array
    {
        $row = DataScopeTable::where('id', $id)->find();
        if (!$row) {
            throw new ApiException(I18n::t('data_scope_table.not_found'), 404);
        }

        return $row->toArray();
    }
}
