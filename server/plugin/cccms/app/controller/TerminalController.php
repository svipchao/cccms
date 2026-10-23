<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\TerminalLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 在线命令终端（F2）。
 *
 * 命令表在 `config/terminal.php`，**不存在「自由 shell」入口**：
 * 接口只接受登记过的 `key` 与声明过的参数，执行走队列。
 *
 * 权限拆四个节点：
 *   - `index`   看命令目录（不含参数值）
 *   - `run`     执行普通命令
 *   - `danger`  执行 risk >= 2 的命令（还需请求带 `confirm`）
 *   - `history` / `logs`  看历史与输出（输出里可能含路径、表名，单独授权）
 */
class TerminalController extends BaseController
{
    #[Permission(slug: 'cccms:terminal:index', title: '在线终端')]
    public function index(): Response
    {
        return $this->ok(TerminalLogic::catalog());
    }

    #[Permission(slug: 'cccms:terminal:history', title: '终端执行记录')]
    public function history(): Response
    {
        return $this->ok(['list' => TerminalLogic::history()]);
    }

    #[Permission(slug: 'cccms:terminal:logs', title: '终端执行输出')]
    public function logs(Request $request): Response
    {
        return $this->ok(TerminalLogic::logs((int)$request->get('job_id', 0)));
    }

    /**
     * 投递一条命令（异步执行，返回 job_id）。
     *
     * `confirm` 在 `risk >= 2` 时必须是命令 key 本身：前端弹窗只是提示，真正的闸门在这里。
     */
    #[Permission(slug: 'cccms:terminal:run', title: '执行终端命令')]
    #[Restrict(methods: ['POST'])]
    public function run(Request $request): Response
    {
        $params = $request->post('params', []);

        $result = TerminalLogic::run(
            (string)$request->post('key', ''),
            is_array($params) ? $params : [],
            (string)$request->post('confirm', ''),
            $request->user->hasPermission('cccms:terminal:danger'),
            (int)$request->user->id
        );

        return $this->ok($result, I18n::t('terminal.submitted'));
    }
}
