<?php

declare(strict_types=1);

use plugin\cccms\app\logic\BackupLogic;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\DbBackup;
use think\facade\Db;

/**
 * F3：数据库备份 / 还原。
 *
 * **只在隔离表上验证还原**：全量还原会把 dev 库整个覆盖掉，那是运维动作，不是测试动作。
 * 用例自己建一张 `sys_itest_backup`，备份 → 改数据 → 只还原这张表 → 断言数据回来了。
 *
 * 重点回归：
 * 1. 归档是 `.sql.gz` + sha256，元信息落 `sys_db_backup`；
 * 2. 引号 / 反斜杠 / 换行在「导出 → 还原」后原样保留（最容易在拼 SQL 时写坏）；
 * 3. 还原前必须自动 pre_restore 备份，且只还原选中的表（别的表不受影响）；
 * 4. 确认串不对 / 选了归档里没有的表 / 删进行中的备份 → 一律拒绝，且**不动数据**。
 *
 * 需要真实 MySQL；mysqldump 缺失时走 PDO 降级路径（Windows 开发机即如此）。
 */
return static function (): void {
    suite('数据库备份 / 还原（F3）');

    $userId = 99999;
    $prefix = (string)Db::connect()->getConfig('prefix');
    $full   = $prefix . 'itest_backup';

    $dropTable = static function () use ($full): void {
        Db::execute('DROP TABLE IF EXISTS `' . $full . '`');
    };

    $cleanup = static function () use ($userId, $dropTable): void {
        foreach (Db::name('db_backup')->where('user_id', $userId)->select()->toArray() as $row) {
            if ((string)$row['file_path'] !== '') {
                DbBackup::delete(DbBackup::path((string)$row['file_path']));
            }
        }
        Db::name('db_backup')->where('user_id', $userId)->delete();
        $dropTable();
    };

    $seed = static function () use ($full): void {
        Db::execute(
            'CREATE TABLE `' . $full . '` ('
            . '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'
            . '`name` varchar(64) NOT NULL,'
            . '`note` text NULL,'
            . 'PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        Db::name('itest_backup')->insertAll([
            ['name' => '中文', 'note' => "带'引号、\\反斜杠\n和换行"],
            ['name' => 'b', 'note' => null],
        ]);
    };

    test('备份：生成 .sql.gz + sha256，元信息落库', function () use ($seed, $full, $userId, $cleanup): void {
        try {
            $seed();
            $result = BackupLogic::create(BackupLogic::KIND_MANUAL, $userId);

            ok($result['id'] > 0, '应返回备份记录 id');
            ok(in_array($result['engine'], [DbBackup::ENGINE_MYSQLDUMP, DbBackup::ENGINE_PDO], true), '引擎应是 mysqldump 或 pdo');
            ok($result['size'] > 0, '归档不应为空');
            ok($result['duration'] >= 0, '应记录耗时');

            $row = Db::name('db_backup')->where('id', $result['id'])->find();
            same(BackupLogic::STATUS_DONE, (int)$row['status'], '备份成功应置为完成');
            same(64, strlen((string)$row['checksum']), '应记录 sha256（64 位十六进制）');
            ok($row['file_path'] !== '' && is_file(DbBackup::path((string)$row['file_path'])), '归档文件应存在');
            ok(str_ends_with((string)$row['file_path'], '.sql.gz'), '归档应是 .sql.gz');
            $tables = BackupLogic::tables($result['id']);
            ok(in_array($full, $tables['tables'], true), '归档表清单应包含测试表');
            ok($tables['database'] !== '', '应带回当前库名供还原确认使用');
        } finally {
            $cleanup();
        }
    }, true);

    test('还原：只还原选中的表，引号/换行原样回来，且先自动备份', function () use ($seed, $full, $userId, $cleanup): void {
        try {
            $seed();
            $backup = BackupLogic::create(BackupLogic::KIND_MANUAL, $userId);

            // 弄坏：清空并塞一条「不该活下来」的数据
            Db::name('itest_backup')->where('id', '>', 0)->delete();
            Db::name('itest_backup')->insert(['name' => 'marker', 'note' => 'should disappear']);

            $database = DbBackup::connection()['database'];
            $result   = BackupLogic::restore($backup['id'], [$full], $database, $userId);

            same(1, $result['tables'], '应只还原 1 张表');
            ok($result['statements'] > 0, '应执行若干语句');
            ok($result['pre_backup_id'] > 0, '还原前应自动备份');

            $pre = Db::name('db_backup')->where('id', $result['pre_backup_id'])->find();
            same(BackupLogic::KIND_PRE_RESTORE, (string)$pre['kind'], '自动备份的类型应是 pre_restore');
            same($userId, (int)$pre['user_id'], '自动备份应记在发起人名下');

            same(2, (int)Db::name('itest_backup')->count(), '应恢复为备份时的 2 行');
            same(0, (int)Db::name('itest_backup')->where('name', 'marker')->count(), '备份之后写入的数据应被覆盖');

            $note = (string)Db::name('itest_backup')->where('name', '中文')->value('note');
            same("带'引号、\\反斜杠\n和换行", $note, '引号 / 反斜杠 / 换行应原样还原');
        } finally {
            $cleanup();
        }
    }, true);

    test('护栏：确认串不对 / 表不在归档里 → 拒绝且不动数据', function () use ($seed, $userId, $cleanup, $prefix): void {
        try {
            $seed();
            $backup = BackupLogic::create(BackupLogic::KIND_MANUAL, $userId);
            Db::name('itest_backup')->insert(['name' => 'marker', 'note' => null]);

            $database = DbBackup::connection()['database'];

            try {
                BackupLogic::restore($backup['id'], [], 'wrong-name', $userId);
                fail('确认串不匹配应抛异常，但没有抛');
            } catch (ApiException $e) {
                same(422, $e->getCode(), '确认串不匹配应是 422');
                contains($database, $e->getMessage(), '提示里应给出正确的库名');
            }

            try {
                BackupLogic::restore($backup['id'], [$prefix . 'not_in_dump'], $database, $userId);
                fail('归档里没有的表应抛异常，但没有抛');
            } catch (ApiException $e) {
                same(422, $e->getCode(), '表不存在应是 422');
            }

            same(3, (int)Db::name('itest_backup')->count(), '被拒绝的还原不能改动任何数据');
        } finally {
            $cleanup();
        }
    }, true);

    test('删除：进行中的不允许删，完成的删记录 + 删文件', function () use ($seed, $userId, $cleanup): void {
        try {
            $seed();
            $backup = BackupLogic::create(BackupLogic::KIND_MANUAL, $userId);
            $file   = DbBackup::path((string)Db::name('db_backup')->where('id', $backup['id'])->value('file_path'));

            $runningId = (int)Db::name('db_backup')->insertGetId([
                'file_name'   => '',
                'file_path'   => '',
                'size'        => 0,
                'checksum'    => '',
                'engine'      => '',
                'table_count' => 0,
                'row_count'   => 0,
                'kind'        => BackupLogic::KIND_MANUAL,
                'status'      => BackupLogic::STATUS_RUNNING,
                'duration'    => 0,
                'error'       => '',
                'user_id'     => $userId,
            ]);

            try {
                BackupLogic::delete([$runningId]);
                fail('进行中的备份不应被删除，但没有抛异常');
            } catch (ApiException $e) {
                same(422, $e->getCode(), '进行中不可删应是 422');
            }

            same(1, BackupLogic::delete([$backup['id']]), '完成的备份可以删除');
            ok(!is_file($file), '归档文件应一并删除');
            ok(Db::name('db_backup')->where('id', $backup['id'])->find() === null, '记录应删除');
        } finally {
            $cleanup();
        }
    }, true);

    test('清理用例数据', function () use ($cleanup): void {
        $cleanup();
        ok(true);
    }, true);
};
