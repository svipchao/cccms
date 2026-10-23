<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\MonitorLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 接口监控看板（F4）。
 *
 * 三个动作都是**只读聚合**，数据源与 `/metrics` 完全一致（都派生自 `sys_log`），
 * 所以这里不加事务、不加缓存：数据新鲜度比多省一次查询更重要（窗口只有 5 分钟）。
 *
 * 权限拆成三个节点而不是一个：看板打开时只需要 `index`，接口明细表与趋势图
 * 可以分别授权给「只看总览」的角色，避免为了看一眼趋势就放开全部明细。
 */
class MonitorController extends BaseController
{
    /** 总览：窗口内请求量 / 错误率 / 分位耗时 / 在线会话 / 依赖状态 / Top 路径 */
    #[Permission(slug: 'cccms:monitor:index', title: '接口监控', sort: 1)]
    public function index(): Response
    {
        return $this->ok(MonitorLogic::overview());
    }

    /** 接口明细（按路径聚合，受 Metrics::PATH_LIMIT 约束） */
    #[Permission(slug: 'cccms:monitor:paths', title: '接口列表', sort: 2)]
    public function paths(): Response
    {
        return $this->ok(MonitorLogic::paths());
    }

    /** 每分钟趋势；`minutes` 会被夹到 10~1440，防止前端传个天量把库拖死 */
    #[Permission(slug: 'cccms:monitor:trend', title: '接口趋势', sort: 3)]
    public function trend(Request $request): Response
    {
        return $this->ok(MonitorLogic::trend((int)$request->get('minutes', 60)));
    }

    /**
     * 服务器资源（F6）：CPU / 负载 / 内存 / 磁盘 / 进程 / Redis / MySQL。
     *
     * 单独一个节点：它读的是**机器**而不是接口，授权粒度与接口监控不同
     * （只给「看流量」的角色没必要看到主机负载）。
     */
    #[Permission(slug: 'cccms:monitor:server', title: '服务器资源', sort: 4)]
    public function server(): Response
    {
        return $this->ok(MonitorLogic::server());
    }
}
