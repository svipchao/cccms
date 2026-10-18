<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\SqlFileRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use think\facade\Db;
use Throwable;

/**
 * 初始化 / 更新数据库（幂等，可重复执行）。
 *
 * db/schema.sql（CREATE TABLE IF NOT EXISTS）与 db/seed.sql
 * （INSERT IGNORE / INSERT ... WHERE NOT EXISTS）是数据库的唯一权威定义，
 * 本命令直接执行它们，再执行各业务插件的 schema.sql / seed.sql。
 *
 * **老库的增量列**：`CREATE TABLE IF NOT EXISTS` 不会给已存在的表补列，
 * 因此新增列同时登记在 {@see self::COLUMNS} / {@see self::INDEXES} 里，按
 * `information_schema` 判断后执行 ALTER（幂等，可重复跑）。
 */
#[AsCommand('cccms:db-upgrade', '初始化/更新数据库结构与种子数据（幂等，可重复执行）')]
class DbUpgradeCommand extends Command
{
    /**
     * 老库需要补的列：[表, 列名, DDL]。
     *
     * 新库由 `schema.sql` 建出同样的列；这里只负责让老库追上，两边定义必须一致。
     */
    private const COLUMNS = [
        [
            'sys_log',
            'action_id',
            "ALTER TABLE `sys_log` ADD COLUMN `action_id` varchar(32) NOT NULL DEFAULT ''"
            . " COMMENT '操作 ID(一次用户操作触发的多个请求共用)' AFTER `trace_id`",
        ],
        [
            'sys_log',
            'session_id',
            "ALTER TABLE `sys_log` ADD COLUMN `session_id` varchar(32) NOT NULL DEFAULT ''"
            . " COMMENT '会话 ID(刷新/重开页面即换，审计轨迹用)' AFTER `action_id`",
        ],
        [
            'sys_log',
            'hash_version',
            "ALTER TABLE `sys_log` ADD COLUMN `hash_version` tinyint unsigned NOT NULL DEFAULT 0"
            . " COMMENT '哈希字段集版本(0=16 字段；1=含 action_id/session_id)' AFTER `row_hash`",
        ],
    ];

    /** 老库需要补的索引：[表, 索引名, DDL] */
    private const INDEXES = [
        ['sys_log', 'idx_action_id', 'ALTER TABLE `sys_log` ADD INDEX `idx_action_id` (`action_id`)'],
        ['sys_log', 'idx_session_id', 'ALTER TABLE `sys_log` ADD INDEX `idx_session_id` (`session_id`)'],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // cccms 自身：先建表（schema）再灌种子（seed）
        $base = base_path() . '/plugin/cccms/db/';
        foreach (['schema.sql', 'seed.sql'] as $name) {
            try {
                $n = SqlFileRunner::run($base . $name);
                $output->writeln("  <info>核心 SQL</info> {$name}（{$n} 条）");
            } catch (Throwable $e) {
                $output->writeln("  <error>核心 SQL 失败</error> {$name}：{$e->getMessage()}");

                return Command::FAILURE;
            }
        }

        // 老库补列 / 补索引（新库在 schema.sql 里已建好，这里只做幂等追赶）
        try {
            $patched = $this->migrateColumns($output);
            if ($patched > 0) {
                $output->writeln("  <info>增量结构</info> 补齐 {$patched} 项");
            }
        } catch (Throwable $e) {
            $output->writeln("  <error>增量结构失败</error>：{$e->getMessage()}");

            return Command::FAILURE;
        }

        // 业务插件建表：执行 plugin/*/db/schema.sql（幂等）
        $pluginStatements = 0;
        foreach (SqlFileRunner::pluginSchemaFiles() as $file) {
            $plugin = SqlFileRunner::pluginNameOf($file);
            try {
                $n = SqlFileRunner::run($file);
                $pluginStatements += $n;
                $output->writeln("  <info>插件 schema</info> {$plugin}（{$n} 条）");
            } catch (Throwable $e) {
                $output->writeln("  <error>插件 schema 失败</error> {$plugin}：{$e->getMessage()}");

                return Command::FAILURE;
            }
        }
        if ($pluginStatements > 0) {
            $output->writeln("<info>业务插件表结构：共执行 {$pluginStatements} 条语句</info>");
        }

        // 业务插件种子数据：执行 plugin/*/db/seed.sql（幂等）
        $pluginSeeds = 0;
        foreach (SqlFileRunner::pluginSeedFiles() as $file) {
            $plugin = SqlFileRunner::pluginNameOf($file);
            try {
                $n = SqlFileRunner::run($file);
                $pluginSeeds += $n;
                $output->writeln("  <info>插件 seed</info> {$plugin}（{$n} 条）");
            } catch (Throwable $e) {
                $output->writeln("  <error>插件 seed 失败</error> {$plugin}：{$e->getMessage()}");

                return Command::FAILURE;
            }
        }
        if ($pluginSeeds > 0) {
            $output->writeln("<info>业务插件种子数据：共执行 {$pluginSeeds} 条语句</info>");
        }

        return Command::SUCCESS;
    }

    /**
     * 按 `information_schema` 判断后补列 / 补索引，返回实际执行的语句数。
     *
     * 不直接把这些 ALTER 写进 schema.sql：MySQL 8 不支持 `ADD COLUMN IF NOT EXISTS`，
     * 重复执行会报错，而 db-upgrade 的约定是**可重复跑**。
     */
    private function migrateColumns(OutputInterface $output): int
    {
        $patched = 0;

        foreach (self::COLUMNS as [$table, $column, $ddl]) {
            if ($this->exists('COLUMNS', 'COLUMN_NAME', $table, $column)) {
                continue;
            }
            Db::execute($ddl);
            $patched++;
            $output->writeln("  <info>补列</info> {$table}.{$column}");
        }

        foreach (self::INDEXES as [$table, $index, $ddl]) {
            if ($this->exists('STATISTICS', 'INDEX_NAME', $table, $index)) {
                continue;
            }
            Db::execute($ddl);
            $patched++;
            $output->writeln("  <info>补索引</info> {$table}.{$index}");
        }

        return $patched;
    }

    /** 当前库的某张表是否已有某列 / 某索引 */
    private function exists(string $catalog, string $nameColumn, string $table, string $name): bool
    {
        $rows = Db::query(
            "SELECT 1 FROM information_schema.{$catalog}"
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND ' . $nameColumn . ' = ? LIMIT 1',
            [$table, $name]
        );

        return $rows !== [];
    }
}
