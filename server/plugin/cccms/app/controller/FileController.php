<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\controller\concern\CategoryCrud;
use plugin\cccms\app\logic\FileLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\ChunkUpload;
use plugin\cccms\support\Download;
use plugin\cccms\support\FileStorage;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class FileController extends BaseController
{
    use CategoryCrud;

    /** 允许内联渲染（预览）的类型；与 `StorageGuard::INLINE_SAFE` 同一口径 */
    private const INLINE_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'ico'  => 'image/x-icon',
        'pdf'  => 'application/pdf',
    ];

    #[Permission(slug: 'cccms:file:index', title: '附件列表')]
    public function index(Request $request): Response
    {
        return $this->ok(FileLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:file:upload', title: '上传附件')]
    #[Restrict(methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file) {
            throw new ApiException(I18n::t('file.file_required'), 422);
        }
        // 带 category_id 时直接归入左侧选中的分类
        return $this->ok(
            FileLogic::upload($file, $request->user->id, (int)$request->input('category_id', 0)),
            I18n::t('file.uploaded')
        );
    }

    /**
     * 分片上传第一步：校验类型 / 大小 → 尝试秒传 → 建会话。
     *
     * 分片上传有独立权限节点 `cccms:file:upload:chunk`：它与单请求的 `cccms:file:upload`
     * 是两个按钮节点，管理员可以只给「传大文件」的岗位开分片、不给普通岗位开。
     * 后两步 `chunk` / `complete` 用 `#[NoAuth]`（登录即可）——它们没有独立的可授权语义，
     * 会话只能由「通过了 `init` 校验的本人」创建，`ChunkUpload::load()` 还会校验归属，
     * 因此没有节点也不会被越权调用。
     */
    #[Permission(slug: 'cccms:file:upload:chunk', title: '分片上传')]
    #[Restrict(methods: ['POST'])]
    public function uploadInit(Request $request): Response
    {
        return $this->ok(ChunkUpload::init($request->post(), $request->user->id));
    }

    /** 分片上传第二步：接收一片（幂等，可重传） */
    #[NoAuth(title: '分片上传')]
    #[Restrict(methods: ['POST'])]
    public function uploadChunk(Request $request): Response
    {
        return $this->ok(ChunkUpload::receive(
            (string)$request->post('upload_id', ''),
            (int)$request->post('index', -1),
            $request->file('chunk'),
            $request->user->id
        ));
    }

    /** 分片上传第三步：合并入库（校验片齐 / 大小 / 哈希） */
    #[NoAuth(title: '分片上传')]
    #[Restrict(methods: ['POST'])]
    public function uploadComplete(Request $request): Response
    {
        $result = ChunkUpload::complete((string)$request->post('upload_id', ''), $request->user->id);

        return $this->ok($result, I18n::t('file.uploaded'));
    }

    #[Permission(slug: 'cccms:file:delete', title: '删除附件')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        FileLogic::delete((int)$request->input('id', 0));
        return $this->ok(null, I18n::t('common.deleted'));
    }

    /**
     * 切换单个附件的「匿名可读」状态。
     *
     * 关闭后该附件的 `/storage/...` 直链立即失效（`StorageGuard` 返回 403），
     * 只能在登录后通过 `GET /file/download` 读取 —— 静态直出没有登录态，
     * 无法做到「按人可见」，因此粒度是「公开 / 私有」而不是「按角色」。
     */
    #[Permission(slug: 'cccms:file:public', title: '设置附件公开状态')]
    #[Restrict(methods: ['POST'])]
    public function setPublic(Request $request): Response
    {
        FileLogic::setPublic(
            (int)$request->input('id', 0),
            filter_var($request->input('is_public', false), FILTER_VALIDATE_BOOLEAN)
        );

        return $this->ok(null, I18n::t('common.updated'));
    }

    /**
     * 私有附件的读取入口：登录即可（不要求附件菜单权限，否则业务页面显示不了自己传的图），
     * 可见性由数据权限决定（越权 403、不存在 404）。
     *
     * - `raw=1`：按扩展名给 `Content-Type` 并 inline 输出 —— 前端取 blob 后喂给 `<img>` /
     *   PDF 的 `iframe`（`<img src>` 带不了 Bearer 头，这是私有附件预览的唯一通路）；
     * - 默认：按附件下载，`Content-Disposition` 带原始文件名。
     */
    #[NoAuth(title: '下载附件')]
    public function download(Request $request): Response
    {
        ['row' => $row, 'absolute' => $absolute] = FileLogic::findForDownload((int)$request->get('id', 0));

        if ($absolute === null) {
            // 云存储：访问控制由对方负责（私有 bucket 需在云侧配签名 URL）
            return redirect(FileStorage::url((string)$row['path']));
        }

        $response = new Response(200, [
            'X-Content-Type-Options' => 'nosniff',
            // 私有内容不进共享缓存：代理 / 浏览器缓存住就等于绕过了「关闭」
            'Cache-Control'          => 'private, no-store',
        ]);

        if ($request->get('raw')) {
            $ext = strtolower((string)$row['ext']);

            return $response
                ->withHeaders(['Content-Type' => self::INLINE_MIME[$ext] ?? 'application/octet-stream'])
                ->file($absolute);
        }

        return $response
            ->withHeaders([
                'Content-Type'        => 'application/octet-stream',
                'Content-Disposition' => Download::disposition((string)($row['original_name'] ?: $row['name'])),
            ])
            ->file($absolute);
    }

    #[Permission(slug: 'cccms:file:move', title: '移动附件到分类')]
    #[Restrict(methods: ['POST'])]
    public function move(Request $request): Response
    {
        $count = FileLogic::move(
            (array)$request->input('ids', []),
            (int)$request->input('category_id', 0)
        );
        return $this->ok(['count' => $count], I18n::t('common.moved'));
    }

    // ---- 附件分类 ----
    // 与字典分类共用 sys_category 表与 CategoryLogic，slug 按模块拆开以便独立授权。
    // 方法体在 CategoryCrud trait 里（与字典分类逐行同构），这里只保留权限注解。

    protected function categoryModule(): string
    {
        return 'file';
    }

    #[Permission(slug: 'cccms:file:category', title: '附件分类列表')]
    public function category(Request $request): Response
    {
        return $this->categoryTree($request);
    }

    #[Permission(slug: 'cccms:file:category_save', title: '新增附件分类')]
    #[Restrict(methods: ['POST'])]
    public function categorySave(Request $request): Response
    {
        return $this->categoryCreate($request);
    }

    #[Permission(slug: 'cccms:file:category_update', title: '更新附件分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryUpdate(Request $request): Response
    {
        return $this->categoryModify($request);
    }

    #[Permission(slug: 'cccms:file:category_delete', title: '删除附件分类')]
    #[Restrict(methods: ['POST'])]
    public function categoryDelete(Request $request): Response
    {
        return $this->categoryRemove($request);
    }
}
