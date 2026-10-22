<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\PageInput;
use plugin\cccms\app\model\Category;
use plugin\cccms\app\model\File;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FileStorage;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\SortInput;
use Webman\Http\UploadFile;

/**
 * 附件管理逻辑。
 *
 * 查询统一走 `File` 模型：附件表**参与**数据权限（见 `app/model/File.php`），
 *   - 「仅本人」= 我上传的附件（create_by）；
 *   - 「本部门及以下」= 可见部门成员上传的附件。
 *
 * 因此越权附件在列表、移动、删除上自然不可见 / 不可动，这里不需要额外判定。
 */
final class FileLogic
{
    /**
     * 列头可排序列：前端 prop => 真实列名。
     *
     * `category_name` / `preview` / `size_text` 等展示字段不在表里（或由前端派生），不登记。
     */
    private const SORTABLE = [
        // 附件只关心「大小」和「上传时间」；文件名/扩展名排序没有实际用途
        'size'        => 'size',
        'create_time' => 'create_time',
    ];

    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除），与正常列表共用同一套列
        $query = !empty($params['trashed']) ? File::onlyTrashed() : File::newScopedQuery();
        if (!empty($params['original_name'])) {
            $query->where('original_name', 'like', '%' . $params['original_name'] . '%');
        }
        if (!empty($params['ext'])) {
            $query->where('ext', 'like', '%' . strtolower((string)$params['ext']) . '%');
        }
        // 分类筛选：-1 未分类，>0 该分类及其下级（见 CategoryLogic::scopeIds）
        $categoryIds = CategoryLogic::scopeIds($params['category_id'] ?? null);
        if ($categoryIds !== null) {
            $query->whereIn('category_id', $categoryIds);
        }
        // 上传时间范围（列头时间筛选，值已归一化为 Y-m-d H:i:s）
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);
        if ($start !== '') {
            $query->where('create_time', '>=', $start);
        }
        if ($end !== '') {
            $query->where('create_time', '<=', $end);
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE);
        $list = $query->page($page, $limit)->select()->toArray();

        // 分类是公共基础数据（模型声明不参与数据权限），这里用模型统一查询入口
        $categories = Category::withoutGlobalScope()->column('name', 'id');

        // URL 按当前 url_prefix 实时生成，切换 CDN/OSS 后历史数据无需迁移；
        // is_image 由 upload.image_ext 决定，前端据此决定是否显示缩略图；
        // is_pdf 决定前端用内置 PDF 阅读器（iframe）还是回退到「查看 / 下载」
        foreach ($list as &$row) {
            $row['url']           = FileStorage::url((string)$row['path']);
            $row['is_image']      = FileStorage::isImage((string)$row['ext']);
            $row['is_pdf']        = self::isPdf((string)$row['ext']);
            $row['is_public']     = (int)($row['is_public'] ?? 0);
            $row['category_name'] = $categories[(int)$row['category_id']] ?? '';
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    /** 批量移动到分类（category_id <= 0 表示移出分类，落到「未分类」）。 */
    public static function move(array $ids, int $categoryId): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new ApiException(I18n::t('file.move_select_required'), 422);
        }

        if ($categoryId > 0) {
            $exists = Category::withoutGlobalScope()
                ->where('id', $categoryId)
                ->where('module', 'file')
                ->find();
            if (!$exists) {
                throw new ApiException(I18n::t('file.category_not_found'), 404);
            }
        } else {
            $categoryId = 0;
        }

        // 带作用域：范围外的附件不会被移动（更新不到任何行）
        return File::newScopedQuery()
            ->whereIn('id', $ids)
            ->update(['category_id' => $categoryId]);
    }

    /**
     * 秒传：内容（`hash` + `size`）完全相同且**在当前数据范围内可见**时，直接复用该记录。
     *
     * 与 `upload()` 的去重同一口径 —— 都走带作用域的查询。否则等于把别人上传的
     * 附件路径告诉当前用户：命中即返回，连字节都不用传。
     *
     * @return array<string,mixed>|null
     */
    public static function findReusable(string $hash, int $size): ?array
    {
        if ($hash === '' || $size <= 0) {
            return null;
        }

        $exist = File::newScopedQuery()->where('hash', $hash)->where('size', $size)->find();

        return $exist ? self::decorate($exist->toArray()) : null;
    }

    /**
     * @param int|null $maxBytes 本次允许的最大字节数；null = 用 `upload.max_size`
     *                           （分片上传合并出的文件可超过单请求上限，由调用方传入）
     */
    public static function upload(UploadFile $file, int $userId, int $categoryId = 0, ?int $maxBytes = null): array
    {
        $info = FileStorage::upload($file, $maxBytes);

        // 去重：相同内容已存在则复用记录，丢弃新文件（回收站里的不算，避免「复活」已删附件）。
        // 刻意走**带作用域**的查询：范围外的同 hash 文件不该被「复用」——
        // 那等于把别人上传的附件路径告诉当前用户；查不到就正常新建一条记录。
        $exist = File::newScopedQuery()->where('hash', $info['hash'])->find();
        if ($exist) {
            FileStorage::delete($info['path']);

            return self::decorate($exist->toArray());
        }

        $data = [
            'name'          => $info['name'],
            'original_name' => $info['original_name'],
            'path'          => $info['path'],
            'url'           => $info['url'],
            'category_id'   => max(0, $categoryId),
            'ext'           => $info['ext'],
            'mime'          => $info['mime'],
            'size'          => $info['size'],
            'driver'        => FileStorage::driver(),
            'hash'          => $info['hash'],
            'create_by'     => $userId,
            'create_time'   => date('Y-m-d H:i:s'),
        ];

        // 新增的归属由 create_by 指定，插入语句不需要数据权限条件
        $id = (int)File::withoutGlobalScope()->insertGetId($data);

        // 直接用刚写入的数据回显，不再按 id 回查：「本部门」档且未分配部门时作用域是
        // fail-closed 的，回查会查不到自己刚上传的记录
        return self::decorate($data + ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        $file = File::newScopedQuery()->where('id', $id)->find();
        if (!$file) {
            // 区分「不存在」与「越权」
            if (File::withoutGlobalScope()->where('id', $id)->find()) {
                throw new ApiException(I18n::t('file.no_permission'), 403);
            }

            throw new ApiException(I18n::t('file.not_found'), 404);
        }

        // 软删除：只进回收站，**刻意不删物理文件**——否则回收站恢复出来的是坏链接。
        // 物理文件在「彻底删除」时才清理（见 RecycleLogic::forceDelete）。
        File::destroy($id);
    }

    /** 补全 url / is_image / is_pdf 这类「按当前配置实时生成」的字段 */
    private static function decorate(array $row): array
    {
        $row['url']       = FileStorage::url((string)$row['path']);
        $row['is_image']  = FileStorage::isImage((string)$row['ext']);
        $row['is_pdf']    = self::isPdf((string)$row['ext']);
        $row['is_public'] = (int)($row['is_public'] ?? 0);
        return $row;
    }

    /**
     * 切换单个附件的匿名可读状态。
     *
     * 私有附件（`is_public = 0`）的直链会被 `StorageGuard` 拒绝，只能登录后经
     * `GET /file/download` 读取 —— 静态直出没有登录态，做不到「按人可见」，
     * 所以用「公开 / 私有」这一档粒度把风险交还给上传者。
     */
    public static function setPublic(int $id, bool $public): void
    {
        $file = File::newScopedQuery()->where('id', $id)->find();
        if (!$file) {
            // 区分「不存在」与「越权」，与删除同一套口径
            if (File::withoutGlobalScope()->where('id', $id)->find()) {
                throw new ApiException(I18n::t('file.no_permission'), 403);
            }

            throw new ApiException(I18n::t('file.not_found'), 404);
        }

        File::newScopedQuery()->where('id', $id)->update(['is_public' => $public ? 1 : 0]);
    }

    /**
     * 取要下载的附件（登录态 + 数据权限）：越权 403、不存在 404。
     *
     * @return array{row:array<string,mixed>,absolute:?string} absolute 仅本地驱动有值
     */
    public static function findForDownload(int $id): array
    {
        $file = File::newScopedQuery()->where('id', $id)->find();
        if (!$file) {
            if (File::withoutGlobalScope()->where('id', $id)->find()) {
                throw new ApiException(I18n::t('file.no_permission'), 403);
            }

            throw new ApiException(I18n::t('file.not_found'), 404);
        }

        $row      = $file->toArray();
        $absolute = FileStorage::driver() === 'local' ? FileStorage::localPath((string)$row['path']) : null;

        // 本地驱动且文件已不在盘上：不要回一个 200 空响应
        if (FileStorage::driver() === 'local' && $absolute === null) {
            throw new ApiException(I18n::t('file.not_found'), 404);
        }

        return ['row' => $row, 'absolute' => $absolute];
    }

    /**
     * 是否 PDF（前端据此用 iframe 内嵌浏览器阅读器）。
     *
     * 只按扩展名判断，不走 MIME：本地上传时 `mime` 来自客户端上报，不可信；
     * 而扩展名是上传白名单校验过的，且对象存储也是按 key 扩展名推断 Content-Type。
     */
    private static function isPdf(string $ext): bool
    {
        return strtolower($ext) === 'pdf';
    }
}
