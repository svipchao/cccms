<?php

declare(strict_types=1);

/** 数据库备份 / 还原（F3）文案。 */
return [
    'not_found'          => '备份不存在',
    'not_ready'          => '备份尚未完成（或已失败），不能使用',
    'already_finished'   => '该备份任务已结束，无需重复执行',
    'failed'             => '备份失败：{reason}',
    'file_missing'       => '备份文件已不存在（可能被手工清理），请删除该记录',
    'checksum_failed'    => '备份文件校验失败（sha256 不一致），文件可能已损坏，请勿还原',
    'select_none'        => '请先选择要删除的备份',
    'delete_running'     => '进行中的备份不能删除（归档还在写入）',
    'no_database'        => '无法确定当前数据库名，不能还原',
    'confirm_mismatch'   => '请输入当前数据库名 {database} 以确认还原',
    'tables_missing'     => '备份里没有这些表：{tables}',
    'restore_locked'     => '已有还原任务在进行，请等待完成后再试',
    'lock_failed'        => '无法创建还原互斥锁，请检查 runtime/backups 目录权限',
    'created'            => '备份完成',
    'deleted'            => '已删除 {count} 份备份',
    'restore_done'       => '还原完成：{tables} 张表 / {statements} 条语句（还原前已自动备份，记录 #{backup_id}）',
];
