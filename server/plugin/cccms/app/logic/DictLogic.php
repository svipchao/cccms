<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\PageInput;
use plugin\cccms\app\model\DictType;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\DictCache;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\SoftDelete;
use plugin\cccms\support\TenantContext;
use think\facade\Db;
use plugin\cccms\support\Tree;

/** 数据字典逻辑。 */
final class DictLogic
{
    /**
     * 可写字段白名单（防 Mass Assignment）。
     *
     * 类型与数据是两张表，各一份；刻意不含 `id` / `tenant_id` / `create_time` / `delete_time`，
     * `type_id` 属于字典数据（归属校验由 `assertTypeInTenant` 承担）。
     */
    private const TYPE_FIELDS = ['category_id', 'name', 'type', 'status', 'remark'];
    private const DATA_FIELDS = ['type_id', 'label', 'value', 'sort', 'status', 'remark'];

    // ---- 类型 ----
    public static function typePaginate(array $params): array
    {
        $query = SoftDelete::listQuery('dict_type', $params);
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        // 标识列头筛选
        if (!empty($params['type'])) {
            $query->where('type', 'like', '%' . $params['type'] . '%');
        }
        // 分类筛选：-1 未分类，>0 该分类及其下级（见 CategoryLogic::scopeIds）
        $categoryIds = CategoryLogic::scopeIds($params['category_id'] ?? null);
        if ($categoryIds !== null) {
            $query->whereIn('category_id', $categoryIds);
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        // 字典类型是低频基础数据，按创建时间倒序即可（不提供列头排序）
        $list = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        $categories = SoftDelete::apply(TenantContext::table('category'))->column('name', 'id');
        foreach ($list as &$row) {
            $row['category_name'] = $categories[(int)$row['category_id']] ?? '';
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    public static function typeCreate(array $data): int
    {
        $data = FilterInput::only($data, self::TYPE_FIELDS);

        // 唯一索引不做软删特例：回收站里的字典类型仍占用标识。
        // `uk_type` 是**全局唯一索引**（标识不随租户重复），因此查重同样不按租户收敛。
        $type = (string)($data['type'] ?? '');
        if ($type !== '') {
            $exist = Db::name('dict_type')->where('type', $type)->find();
            if ($exist) {
                throw new ApiException(
                    SoftDelete::isTrashed($exist)
                        ? I18n::t('dict.type_id_trashed', ['type' => $type])
                        : I18n::t('dict.type_id_exists'),
                    422
                );
            }
        }

        $id = (int)TenantContext::table('dict_type')->insertGetId(TenantContext::stamp('dict_type', $data));
        DictCache::bump();

        return $id;
    }

    public static function typeUpdate(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::TYPE_FIELDS);

        self::assertTypeInTenant($id);
        TenantContext::table('dict_type')->where('id', $id)->update(TenantContext::stamp('dict_type', $data));
        DictCache::bump();
    }

    public static function typeDelete(int $id): void
    {
        self::assertTypeInTenant($id);
        // 软删除：类型与其下数据一起进回收站（两条语句必须同成败，否则会留下孤儿数据）
        Db::transaction(static function () use ($id): void {
            $dataIds = SoftDelete::apply(TenantContext::table('dict_data'))->where('type_id', $id)->column('id');
            if ($dataIds !== []) {
                SoftDelete::remove(TenantContext::table('dict_data'), $dataIds);
            }
            SoftDelete::remove(TenantContext::table('dict_type'), $id);
        });
        DictCache::bump();
    }

    // ---- 数据 ----
    /**
     * 字典数据列表。
     *
     * 回收站视图（`$trashed = true`）直查库、不缓存 —— 低频管理动作无收益，
     * 且会与「恢复 / 彻底删除」产生额外失效点。
     */
    public static function dataList(int $typeId, bool $trashed = false): array
    {
        self::assertTypeInTenant($typeId);

        if (!$trashed) {
            $cached = DictCache::load($typeId);
            if ($cached !== null) {
                return $cached;
            }
        }

        $list = SoftDelete::scope(TenantContext::table('dict_data'), $trashed)
            ->where('type_id', $typeId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (!$trashed) {
            DictCache::store($typeId, $list);
        }

        return $list;
    }

    public static function dataCreate(array $data): int
    {
        $data = FilterInput::only($data, self::DATA_FIELDS);

        $typeId = (int)($data['type_id'] ?? 0);
        if ($typeId <= 0) {
            throw new ApiException(I18n::t('dict.type_not_found'), 422);
        }
        self::assertTypeInTenant($typeId);

        $id = (int)TenantContext::table('dict_data')->insertGetId(TenantContext::stamp('dict_data', $data));
        DictCache::bump();

        return $id;
    }

    public static function dataUpdate(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::DATA_FIELDS);

        // type_id 变更时校验新类型属于当前租户，避免把数据挂到别的租户的类型下
        if (array_key_exists('type_id', $data)) {
            $typeId = (int)$data['type_id'];
            if ($typeId <= 0) {
                throw new ApiException(I18n::t('dict.type_not_found'), 404);
            }
            self::assertTypeInTenant($typeId);
        }

        self::assertDataInTenant($id);

        $data = TenantContext::stamp('dict_data', $data);
        TenantContext::table('dict_data')->where('id', $id)->update($data);
        DictCache::bump();
    }

    public static function dataDelete(int $id): void
    {
        self::assertDataInTenant($id);
        SoftDelete::remove(TenantContext::table('dict_data'), $id);
        DictCache::bump();
    }

    /**
     * 字典类型必须属于当前租户。
     *
     * `dict_data` 只按 `type_id` 关联，没有自己的租户列可依赖：
     * 不校验就能拿别的租户的 type_id 读出 / 写入它的字典数据。
     *
     * `withoutGlobalScope()` 保留租户边界、只解除数据权限；`withTrashed()` 让
     * 「类型已进回收站、单独恢复其下字典数据」这种入口也能通过。
     */
    private static function assertTypeInTenant(int $typeId): void
    {
        if ($typeId <= 0 || DictType::withoutGlobalScope()->withTrashed()->where('id', $typeId)->count() === 0) {
            throw new ApiException(I18n::t('dict.type_not_found'), 404);
        }
    }

    /** 字典数据必须属于当前租户（不存在或越权统一 404，不泄露「存在但看不见」） */
    private static function assertDataInTenant(int $id): void
    {
        if (SoftDelete::apply(TenantContext::table('dict_data'))->where('id', $id)->count() === 0) {
            throw new ApiException(I18n::t('dict.data_not_found'), 404);
        }
    }

    // ---- 批量操作 ----
    // 字典表是**查询构造器直查**（尚未模型化），因此这里用 SoftDelete 显式过滤，
    // 语义与用户 / 岗位一致：只作用于可见行，不可见的 id 跳过并回报。

    /**
     * 批量启用 / 禁用字典类型。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function typeBatchStatus(array $ids, int $status): array
    {
        $ids      = self::batchIds($ids);
        $existing = array_map('intval', SoftDelete::apply(TenantContext::table('dict_type'))->whereIn('id', $ids)->column('id'));
        $affected = $existing === []
            ? 0
            : SoftDelete::apply(TenantContext::table('dict_type'))
                ->whereIn('id', $existing)
                ->update(['status' => $status === 0 ? 0 : 1]);

        DictCache::bump();

        return ['affected' => $affected, 'skipped' => array_values(array_diff($ids, $existing))];
    }

    /**
     * 批量删除字典类型（连同其下数据一起进回收站）。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function typeBatchDelete(array $ids): array
    {
        $ids      = self::batchIds($ids);
        $existing = array_map('intval', SoftDelete::apply(TenantContext::table('dict_type'))->whereIn('id', $ids)->column('id'));

        $affected = 0;
        foreach ($existing as $id) {
            self::typeDelete($id);
            $affected++;
        }

        return ['affected' => $affected, 'skipped' => array_values(array_diff($ids, $existing))];
    }

    /**
     * 批量启用 / 禁用字典数据。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function dataBatchStatus(array $ids, int $status): array
    {
        $ids      = self::batchIds($ids);
        $existing = array_map('intval', SoftDelete::apply(TenantContext::table('dict_data'))->whereIn('id', $ids)->column('id'));
        $affected = $existing === []
            ? 0
            : SoftDelete::apply(TenantContext::table('dict_data'))
                ->whereIn('id', $existing)
                ->update(['status' => $status === 0 ? 0 : 1]);

        DictCache::bump();

        return ['affected' => $affected, 'skipped' => array_values(array_diff($ids, $existing))];
    }

    /**
     * 批量删除字典数据（软删）。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function dataBatchDelete(array $ids): array
    {
        $ids      = self::batchIds($ids);
        $existing = array_map('intval', SoftDelete::apply(TenantContext::table('dict_data'))->whereIn('id', $ids)->column('id'));

        $affected = $existing === [] ? 0 : SoftDelete::remove(TenantContext::table('dict_data'), $existing);
        DictCache::bump();

        return ['affected' => $affected, 'skipped' => array_values(array_diff($ids, $existing))];
    }

    /**
     * 规范化批量 id：去重、去非正整数。
     *
     * @return int[]
     */
    private static function batchIds(array $ids): array
    {
        return Tree::batchIds($ids);
    }
}
