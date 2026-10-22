<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use plugin\cccms\app\model\File;
use plugin\cccms\support\Download;
use plugin\cccms\support\SysConfig;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 本地存储（`public/storage`）静态文件的三道闸：匿名开关、响应头加固、强制下载。
 *
 * 直链由静态文件服务吐出，**不进路由表**，因此没有 `CheckLogin` / `CheckAuth`，也不经过
 * `DataScope`。它带来两类问题，本中间件分别处理：
 *
 * 1. **谁能读**：附件默认公开（兼容存量），上传者可在附件列表把单个附件设为**私有**；
 *    后台配置 `upload.anonymous` 是**总开关**，关掉后所有附件都必须登录后经
 *    `GET /file/download` 读取（该接口有登录态 + 数据权限）。
 * 2. **读到什么**：一律 `X-Content-Type-Options: nosniff`；除图片与 PDF 外强制
 *    `Content-Disposition: attachment`。浏览器会按 Content-Type 内联渲染，一个含
 *    `<script>` 的 SVG 附件，管理员点开预览即执行脚本，而 token 在 `localStorage`，
 *    可被直接读取并接管后台（存储型 XSS）。
 *
 * 上传侧的类型收紧见 `support/storage/StorageDriver`（`DANGEROUS_EXT` + 魔数校验），
 * 本中间件负责**兜住存量文件**：白名单收紧前已落盘的 SVG 也只会被下载。
 *
 * ⚠️ 由 `config/static.php` 挂到 Webman 的静态文件回调上，**只有请求经过后端**时才执行；
 * Nginx 直接 `root` 托管 `public/storage` 会绕过全部三道闸（部署约束见 `docs/02 §5.2`）。
 */
final class StorageGuard
{
    /** 允许内联渲染的类型：图片（缩略图）与 PDF（内置阅读器） */
    private const INLINE_SAFE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'pdf'];

    /** 本地驱动直出的路径前缀（`url_prefix` 默认值；改成独立域名 / CDN 后本中间件不参与） */
    private const PREFIX = 'storage/';

    public function process(Request $request, callable $handler): Response
    {
        $path = ltrim($request->path(), '/');
        if (!str_starts_with($path, self::PREFIX)) {
            return $handler($request);
        }

        $denied = self::denyReason(substr($path, strlen(self::PREFIX)));
        if ($denied !== '') {
            // 先拦再读盘：文件吐出去之后再校验就没有意义了
            return new Response(403, [
                'Content-Type'           => 'text/plain; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                // 状态可能随时被改回公开，不能让浏览器 / 中间层缓存住这个 403
                'Cache-Control'          => 'no-store',
            ], $denied);
        }

        $response = $handler($request);

        $response->withHeaders(['X-Content-Type-Options' => 'nosniff']);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::INLINE_SAFE, true)) {
            // 对象名由服务端随机生成（形如 20260912/103000ab12cd34ef.png），basename 不含路径分隔符
            $response->withHeaders(['Content-Disposition' => Download::disposition(basename($path))]);
        }

        return $response;
    }

    /**
     * 直链是否允许匿名读取：返回空串表示放行，否则是拒绝原因。
     *
     * 判定顺序：总开关 → 单个附件的 `is_public`。查库失败按拒绝处理（静态请求不该
     * 因为数据库抖动而泄漏内容）。
     */
    private static function denyReason(string $object): string
    {
        if (!SysConfig::getBool('upload.anonymous', true)) {
            return '附件匿名访问已关闭，请登录后通过下载接口读取';
        }

        try {
            // 场景 1（尚未建立用户上下文）：静态请求没有登录态，只能按路径反查记录；
            // 公开附件的语义本就是「任何租户可读」，因此这里不能带租户过滤。
            $file = File::withoutAllScopes('静态直链无登录态，只能按路径反查；公开附件的语义本就是跨租户可读')
                ->field('id,is_public')
                ->where('path', $object)
                ->whereNull('delete_time')
                ->find();
        } catch (Throwable) {
            return '附件服务暂不可用，请稍后重试';
        }

        if (!$file) {
            // 不是附件表管理的文件：上传是写入该目录的唯一入口，出现其它文件本身就是异常
            return '附件不存在或已被删除';
        }

        return (int)($file['is_public'] ?? 0) === 1 ? '' : '该附件为私有，请登录后通过下载接口读取';
    }
}
