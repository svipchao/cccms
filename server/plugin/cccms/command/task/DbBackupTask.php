<?php

declare(strict_types=1);

namespace plugin\cccms\command\task;

use plugin\cccms\app\logic\BackupLogic;
use plugin\cccms\support\CrontabTask;

/**
 * 数据库定时备份（F3，框架内置定时任务，默认停用）。
 *
 * 为什么必须默认停用：备份会占磁盘、占数据库读 IO，保留策略也得运维先确认。
 * 管理员在「定时任务」里启用并调整表达式（种子数据默认每天 02:30）。
 *
 * 保留策略在 `BackupLogic::prune()`：定时备份按 `backup.keep_count` 留份数，
 * 所有已完成备份按 `backup.keep_days` 留天数。
 */
class DbBackupTask implements CrontabTask
{
    public function run(array $params = []): string
    {
        return BackupLogic::scheduled();
    }
}
