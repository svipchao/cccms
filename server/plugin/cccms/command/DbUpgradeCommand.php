<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\Migrator;
use plugin\cccms\support\SqlFileRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * 初始化 / 升级数据库（幂等，可重复执行）。
 *
 * 执行顺序（顺序有意义）：
 *   1. 核心 `db/schema.sql`（CREATE TABLE IF NOT EXISTS，字段一次建全）
 *   2. 各业务插件 `db/schema.sql`
 *   3. `db/migrations/*.sql`（结构变更：补列 / 补索引 / 改默认值）
 *   4. 核心 `db/seed.sql`（INSERT IGNORE / WHERE NOT EXISTS）
 *   5. 各业务插件 `db/seed.sql`
 *
 * 迁移必须在 schema 之后（表要先存在），种子在迁移之后（种子可能写新列的默认值）。
 * 每个迁移按版本号只执行一次，记录在 `sys_schema_migration`。
 */
#[AsCommand('cccms:db-upgrade', '初始化 / 升级数据库结构与种子数据（幂等，可重复执行）')]
class DbUpgradeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. 核心建表
        $base = base_path() . '/plugin/cccms/db/';
        try {
            $n = SqlFileRunner::run($base . 'schema.sql');
            $output->writeln("  <info>核心 SQL</info> schema.sql（{$n} 条）");
        } catch (Throwable $e) {
            $output->writeln("  <error>核心 SQL 失败</error> schema.sql：{$e->getMessage()}");

            return Command::FAILURE;
        }

        // 2. 业务插件建表
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

        // 3. 结构迁移（补列 / 补索引 / 改默认值）
        try {
            $migrated = Migrator::run(static function (string $level, string $message) use ($output): void {
                $output->writeln($message);
            });
        } catch (Throwable $e) {
            $output->writeln("  <error>结构迁移失败</error> {$e->getMessage()}");

            return Command::FAILURE;
        }

        if ($migrated['applied'] !== []) {
            $output->writeln('<info>结构迁移：执行 ' . count($migrated['applied']) . ' 个版本</info>');
        } else {
            $output->writeln('  <comment>结构迁移</comment> 已是最新，无需执行');
        }
        if ($migrated['drifted'] !== []) {
            $output->writeln(
                '  <comment>注意</comment> 以下迁移文件在应用后被改动过（不会重跑，如需变更请新增迁移）：'
                . implode('、', $migrated['drifted'])
            );
        }

        // 4. 核心种子
        try {
            $n = SqlFileRunner::run($base . 'seed.sql');
            $output->writeln("  <info>核心 SQL</info> seed.sql（{$n} 条）");
        } catch (Throwable $e) {
            $output->writeln("  <error>核心 SQL 失败</error> seed.sql：{$e->getMessage()}");

            return Command::FAILURE;
        }

        // 5. 业务插件种子数据
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
}
