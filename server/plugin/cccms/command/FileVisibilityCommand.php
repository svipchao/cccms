<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use think\facade\Db;

/**
 * 批量设置附件「匿名可读」状态。
 *
 * 用途：
 *   1. **一次性回填**：老库补上 `is_public` 列后统一收紧（`--private`）或放开（`--public`）；
 *   2. 应急：发现某批附件误设为公开时，按分类 / 扩展名批量收回。
 *
 * 用法：
 *   php webman cccms:file-visibility --private --dry-run    # 先看会影响多少条
 *   php webman cccms:file-visibility --private              # 全部改为私有
 *   php webman cccms:file-visibility --public --category=3   # 只放开某个分类
 *   php webman cccms:file-visibility --private --ext=pdf
 *
 * 安全：本命令只改 `sys_file.is_public`，不动文件、不动归属；`--dry-run` 只统计。
 * 刻意用 `Db::name('file')`（不带模型作用域）：CLI 没有当前用户，要的就是全量。
 */
#[AsCommand('cccms:file-visibility', '批量设置附件匿名可读状态（回填 / 应急收紧）')]
class FileVisibilityCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('public', null, InputOption::VALUE_NONE, '把匹配的附件设为「公开」（直链可匿名读）')
            ->addOption('private', null, InputOption::VALUE_NONE, '把匹配的附件设为「私有」（直链 403）')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, '只处理该分类（0 = 未分类）')
            ->addOption('ext', null, InputOption::VALUE_REQUIRED, '只处理该扩展名（如 pdf，不带点）')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只统计，不写库');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $toPublic  = (bool)$input->getOption('public');
        $toPrivate = (bool)$input->getOption('private');

        if ($toPublic === $toPrivate) {
            $output->writeln('<error>必须且只能指定 --public 或 --private 之一</error>');

            return Command::INVALID;
        }

        $query = Db::name('file');

        if ($input->getOption('category') !== null) {
            $query->where('category_id', max(0, (int)$input->getOption('category')));
        }

        $ext = trim((string)$input->getOption('ext'));
        if ($ext !== '') {
            $query->where('ext', strtolower(ltrim($ext, '.')));
        }

        $target = $toPublic ? 1 : 0;
        $total  = (clone $query)->count();
        $dryRun = (bool)$input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln(sprintf(
                '<info>将把 %d 个附件改为%s（dry-run，未写库）</info>',
                $total,
                $target === 1 ? '公开' : '私有'
            ));

            return Command::SUCCESS;
        }

        $changed = (clone $query)->where('is_public', '<>', $target)->update(['is_public' => $target]);

        $output->writeln(sprintf(
            '<info>共匹配 %d 个附件，实际修改 %d 个（目标：%s）</info>',
            $total,
            (int)$changed,
            $target === 1 ? '公开' : '私有'
        ));

        if ($target === 0) {
            $output->writeln('提示：私有附件的直链会立即 403，前端需登录后经 GET /file/download 读取。');
        }

        return Command::SUCCESS;
    }
}
