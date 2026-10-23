<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\JobLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 任务中心 · 作业队列（F5）。
 *
 * 权限节点按 `cccms:job:*` 声明，但**归属菜单**用 `group: 'cccms:crontab'` 指到
 * 「定时任务」——队列与调度是同一件事的两面（cron 管「什么时候」，作业管「替我做一次」），
 * 单独再开一个菜单只会让运维在两个页面之间来回跳。
 */
class JobController extends BaseController
{
    #[Permission(slug: 'cccms:job:index', title: '作业队列列表', group: 'cccms:crontab')]
    public function index(Request $request): Response
    {
        return $this->ok(JobLogic::paginate($request->get()));
    }

    #[Permission(slug: 'cccms:job:stats', title: '作业队列统计', group: 'cccms:crontab')]
    public function stats(): Response
    {
        return $this->ok(JobLogic::stats());
    }

    #[Permission(slug: 'cccms:job:logs', title: '作业执行日志', group: 'cccms:crontab')]
    public function logs(Request $request): Response
    {
        return $this->ok(JobLogic::logs((int)$request->get('id', 0)));
    }

    #[Permission(slug: 'cccms:job:retry', title: '重试作业', group: 'cccms:crontab')]
    #[Restrict(methods: ['POST'])]
    public function retry(Request $request): Response
    {
        JobLogic::retry((int)$request->post('id', 0));

        return $this->ok(null, I18n::t('job.retried'));
    }

    #[Permission(slug: 'cccms:job:remove', title: '删除作业', group: 'cccms:crontab')]
    #[Restrict(methods: ['POST'])]
    public function remove(Request $request): Response
    {
        $deleted = JobLogic::remove(FilterInput::ints($request->post('ids')));

        return $this->ok(['deleted' => $deleted], I18n::t('job.removed', ['count' => $deleted]));
    }
}
