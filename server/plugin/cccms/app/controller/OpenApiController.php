<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\I18n;
use plugin\cccms\support\OpenApi;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 接口文档（F1）。
 *
 * ## 为什么不直接吐一份静态 JSON
 *
 * 文档有两种消费者：人（后台页面）和机器（Postman / Apifox 导入）。
 * 机器要完整规范，人只要一棵树 + 单个接口的详情 —— 一次返回全量 spec
 * 在几百个接口时是几百 KB，页面首屏会明显变慢。所以拆成三个动作：
 *   - `index`  ：分组树（轻，首屏）
 *   - `detail` ：单接口详情（含参数、权限节点）
 *   - `spec`   ：完整 OpenAPI 3.1 文档（供外部工具导入）
 *
 * ## 与权限的关系
 *
 * 文档本身包含全部接口路径与权限节点，等于一份攻击面清单，因此**必须登录且需要权限**，
 * 不做 `#[NoLogin]`。`spec` 单独一个节点，便于只给少数人开导出权限。
 */
class OpenApiController extends BaseController
{
    /** 分组树 + 接口总数 */
    #[Permission(slug: 'cccms:apidoc:index', title: '接口文档', sort: 1)]
    public function index(): Response
    {
        $groups = OpenApi::tree();

        return $this->ok([
            'groups' => $groups,
            'total' => array_sum(array_map(static fn (array $g): int => count($g['operations']), $groups)),
        ]);
    }

    /** 单接口详情；`id` 形如 `GET /user` */
    #[Permission(slug: 'cccms:apidoc:detail', title: '接口详情', sort: 2)]
    public function detail(Request $request): Response
    {
        $detail = OpenApi::detail((string)$request->get('id', ''));
        if ($detail === null) {
            throw new ApiException(I18n::t('apidoc.not_found'), 404);
        }

        return $this->ok($detail);
    }

    /**
     * 完整 OpenAPI 3.1 文档（非信封格式，供 Apifox / Postman / 代码生成器导入）。
     *
     * `server` 用于覆盖 `servers[0].url`：反向代理改了前缀时（比如挂在 /admin-api 下），
     * 导入方能直接拿到可用地址，而不是先改一遍 spec。
     */
    #[Permission(slug: 'cccms:apidoc:spec', title: '导出 OpenAPI', sort: 3)]
    public function spec(Request $request): Response
    {
        $server = trim((string)$request->get('server', '/api'));

        return new Response(
            200,
            ['Content-Type' => 'application/json; charset=utf-8'],
            (string)json_encode(
                OpenApi::build($server !== '' ? $server : '/api'),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            )
        );
    }
}
