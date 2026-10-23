<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\GeneratorLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class GeneratorController extends BaseController
{
    /**
     * 可用性探针：页面进站先问「能不能用」，不能就直接渲染拦截提示。
     *
     * 刻意不挂权限节点：它只回一个布尔值与被拦截原因，不含任何表结构信息；
     * 页面本身的可见性由菜单节点 `cccms:generator` 控制，按钮能力仍由各自的
     * `cccms:generator:*` 节点约束（写操作的 403 兜底在 Logic 里，与权限无关）。
     */
    #[NoAuth]
    public function status(Request $request): Response
    {
        return $this->ok(GeneratorLogic::environment());
    }

    #[Permission(slug: 'cccms:generator:tables', title: '数据表列表')]
    public function tables(Request $request): Response
    {
        return $this->ok(GeneratorLogic::tables());
    }

    #[Permission(slug: 'cccms:generator:columns', title: '表字段信息')]
    public function columns(Request $request): Response
    {
        return $this->ok(GeneratorLogic::columns((string)$request->input('table', '')));
    }

    #[Permission(slug: 'cccms:generator:preview', title: '生成预览')]
    #[Restrict(methods: ['POST'])]
    public function preview(Request $request): Response
    {
        return $this->ok(GeneratorLogic::preview($request->post()));
    }

    #[Permission(slug: 'cccms:generator:generate', title: '执行生成')]
    #[Restrict(methods: ['POST'])]
    public function generate(Request $request): Response
    {
        return $this->ok(GeneratorLogic::generate($request->post()), I18n::t('common.generated'));
    }
}
