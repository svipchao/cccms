<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Post;
use plugin\cccms\app\model\UserPost;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PageInput;
use plugin\cccms\support\SortInput;
use plugin\cccms\support\Tree;
use plugin\cccms\support\UniqueGuard;

/**
 * 岗位逻辑。
 *
 * 查询统一走 `Post` 模型（当前声明为**不参与**数据权限，见模型里的 `$dataScope`），
 * 这样「表怎么隔离」只在模型里声明一次，而不是散在各处查询里。
 */
final class PostLogic
{
    /**
     * 可写字段白名单（防 Mass Assignment）。
     *
     * 刻意不含 `id` / `tenant_id` / `create_time` / `delete_time`。
     */
    private const FIELDS = ['code', 'name', 'sort', 'status'];

    /** 列头可排序列：前端 prop => 真实列名（未登记的列回落默认排序） */
    private const SORTABLE = [
        // 岗位只按「排序号」调整展示顺序；编码/名称/状态不提供排序
        'sort' => 'sort',
    ];

    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除），与正常列表共用同一套列
        $query = !empty($params['trashed']) ? Post::onlyTrashed() : Post::newScopedQuery();
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        // 列头筛选支持多选，值形如 `1,0`
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE, 'sort', 'asc', 'asc');
        $list = $query->page($page, $limit)->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public static function create(array $data): int
    {
        $data = FilterInput::only($data, self::FIELDS);

        self::assertUniqueCode((string)($data['code'] ?? ''), 0);

        // 新增还没有归属，插入语句不需要数据权限条件
        return (int)Post::withoutGlobalScope()->insertGetId($data);
    }

    public static function update(int $id, array $data): void
    {
        $data = FilterInput::only($data, self::FIELDS);

        self::assertExists($id);
        if (array_key_exists('code', $data)) {
            self::assertUniqueCode((string)$data['code'], $id);
        }
        Post::newScopedQuery()->where('id', $id)->update($data);
    }

    public static function delete(int $id): void
    {
        if (UserPost::where('post_id', $id)->count() > 0) {
            throw new ApiException(I18n::t('post.has_users'), 422);
        }

        // 软删除：进回收站
        Post::destroy($id);
    }

    private static function assertExists(int $id): void
    {
        // 存在性校验看全量（含回收站），显式跳出作用域
        if (!Post::withoutGlobalScope()->where('id', $id)->find()) {
            throw new ApiException(I18n::t('post.not_found'), 404);
        }
    }

    /** 岗位编码全局唯一：软删行仍占用标识，且 uk_code 是全局索引，须绕过租户作用域查重 */
    private static function assertUniqueCode(string $code, int $excludeId): void
    {
        if ($code === '') {
            throw new ApiException(I18n::t('post.code_required'), 422);
        }

        UniqueGuard::assertFree(
            Post::class,
            'code',
            $code,
            $excludeId,
            'post.code_exists',
            'post.code_in_trash',
            ['code' => $code]
        );
    }

    // ---- 批量操作 ----
    // 与用户模块同一套语义：只作用于当前数据范围内的行，范围外的 id 跳过并回报。

    /**
     * 批量启用 / 禁用。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchStatus(array $ids, int $status): array
    {
        $ids     = Tree::batchIds($ids);
        $inScope = self::scopedIds($ids);

        $affected = $inScope === []
            ? 0
            : Post::newScopedQuery()->whereIn('id', $inScope)->update(['status' => $status === 0 ? 0 : 1]);

        return ['affected' => $affected, 'skipped' => array_values(array_diff($ids, $inScope))];
    }

    /**
     * 批量删除（软删）。
     *
     * 与单条删除同一约束：**岗位下还有用户就不允许删**，这类 id 计入跳过而不是抛异常，
     * 否则一个被占用的岗位会让整批都删不掉。
     *
     * @return array{affected:int,skipped:int[]}
     */
    public static function batchDelete(array $ids): array
    {
        $ids     = Tree::batchIds($ids);
        $inScope = self::scopedIds($ids);
        $skipped = array_values(array_diff($ids, $inScope));

        // 一次查出被占用的岗位，避免逐个 count()（N+1）
        $occupied = array_fill_keys(
            array_map('intval', UserPost::whereIn('post_id', $inScope)->column('post_id')),
            true
        );

        $deletable = [];
        foreach ($inScope as $id) {
            if (isset($occupied[$id])) {
                $skipped[] = $id;
            } else {
                $deletable[] = $id;
            }
        }

        $affected = $deletable === [] ? 0 : Post::destroy($deletable);

        return ['affected' => $affected, 'skipped' => $skipped];
    }

    /**
     * 当前数据范围内实际可见的 id。
     *
     * @param  int[] $ids
     * @return int[]
     */
    private static function scopedIds(array $ids): array
    {
        return array_map('intval', Post::newScopedQuery()->whereIn('id', $ids)->column('id'));
    }

}
