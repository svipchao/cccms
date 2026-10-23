<?php

declare(strict_types=1);

namespace plugin\cccms\app\controller;

use plugin\cccms\app\logic\BackupLogic;
use plugin\cccms\basic\BaseController;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 数据库备份 / 还原（F3）。
 *
 * 备份文件是**整库快照**，不参与数据权限（按部门裁出来的备份无法还原任何东西）：
 * 访问控制完全由 `cccms:backup:*` 节点决定，建议只授给平台超管。
 */
class BackupController extends BaseController
{
    #[Permission(slug: 'cccms:backup:index', title: '数据备份')]
    public function index(Request $request): Response
    {
        return $this->ok(BackupLogic::paginate($request->get()));
    }

    /**
     * 立即备份（**同步**执行）。
     *
     * 备份通常几秒到几十秒，同步返回能让「点完就看到文件大小」；几十 GB 的库请用定时任务，
     * 它有独立进程与超时预算，别占在线请求。
     */
    #[Permission(slug: 'cccms:backup:create', title: '新建备份')]
    #[Restrict(methods: ['POST'])]
    public function create(Request $request): Response
    {
        $result = BackupLogic::create(BackupLogic::KIND_MANUAL, (int)$request->user->id);

        return $this->ok($result, I18n::t('backup.created'));
    }

    #[Permission(slug: 'cccms:backup:delete', title: '删除备份')]
    #[Restrict(methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $deleted = BackupLogic::delete(FilterInput::ints($request->post('ids')));

        return $this->ok(['deleted' => $deleted], I18n::t('backup.deleted', ['count' => $deleted]));
    }

    /** 下载归档（校验状态 + 文件存在 + sha256） */
    #[Permission(slug: 'cccms:backup:download', title: '下载备份')]
    public function download(Request $request): Response
    {
        return BackupLogic::download((int)$request->get('id', 0));
    }

    /** 归档里包含哪些表（还原弹窗用） */
    #[Permission(slug: 'cccms:backup:tables', title: '查看备份内容')]
    public function tables(Request $request): Response
    {
        return $this->ok(BackupLogic::tables((int)$request->get('id', 0)));
    }

    /**
     * 从备份还原（**破坏性**）。
     *
     * `confirm` 必须是当前数据库名（前端要求手输）：这是最后一道防误触，
     * 因为 MySQL 的 DDL 无法回滚，一旦点错就是整库被覆盖。
     */
    #[Permission(slug: 'cccms:backup:restore', title: '还原数据库')]
    #[Restrict(methods: ['POST'])]
    public function restore(Request $request): Response
    {
        $tables = FilterInput::values($request->post('tables'));

        $result = BackupLogic::restore(
            (int)$request->post('id', 0),
            $tables,
            (string)$request->post('confirm', ''),
            (int)$request->user->id
        );

        return $this->ok($result, I18n::t('backup.restore_done', [
            'tables'     => $result['tables'],
            'statements' => $result['statements'],
            'backup_id'  => $result['pre_backup_id'],
        ]));
    }
}
