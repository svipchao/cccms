<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\LogLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

class LogController extends BaseController
{
    #[Permission(slug: 'cccms:log:index', title: '操作日志')]
    public function index(Request $request): Response
    {
        return $this->ok(LogLogic::paginate($request->get()));
    }

    /** 导出 CSV（按当前筛选与数据范围；一律异步，结果到「导出任务」面板下载） */
    #[Permission(slug: 'cccms:log:export', title: '导出操作日志')]
    public function export(Request $request): Response
    {
        return $this->ok(LogLogic::export($request->get(), $request->user->id));
    }

    /** 按 trace_id 聚合**一次请求**的日志（报错定位用） */
    #[Permission(slug: 'cccms:log:trace', title: '查看链路日志')]
    public function trace(Request $request): Response
    {
        return $this->ok(LogLogic::trace((string)$request->get('trace_id', '')));
    }

    /**
     * 按 action_id 聚合**一次用户操作**的全部日志（链路视图主视图）。
     *
     * 与 `trace` 分属两个权限节点：排障（看一次操作）与审计（看整段会话）
     * 是两种能力，不应因为「都能看日志」就捆在一起授权。
     */
    #[Permission(slug: 'cccms:log:action', title: '查看操作链路')]
    public function action(Request $request): Response
    {
        return $this->ok(LogLogic::action((string)$request->get('action_id', '')));
    }

    /**
     * 按 session_id 读取**一次页面会话**的轨迹（审计视角）。
     *
     * 数据范围仍由模型作用域兜住，不会因为换了个入口就看到别人的日志。
     */
    #[Permission(slug: 'cccms:log:session', title: '查看会话轨迹')]
    public function session(Request $request): Response
    {
        return $this->ok(LogLogic::session(
            (string)$request->get('session_id', ''),
            (int)$request->get('after_id', 0),
            (int)$request->get('limit', 200)
        ));
    }

    /** 登录安全分析：失败趋势 / TOP 用户名 / TOP IP / 异地登录（受数据范围约束） */
    #[Permission(slug: 'cccms:log:analysis', title: '查看登录分析')]
    public function loginAnalysis(Request $request): Response
    {
        return $this->ok(LogLogic::loginAnalysis($request->get()));
    }

    #[Permission(slug: 'cccms:log:delete', title: '删除日志')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $ids = $request->post('ids', []);
        LogLogic::delete(is_array($ids) ? $ids : []);
        return $this->ok(null, I18n::t('common.deleted'));
    }
}
