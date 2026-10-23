<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\OrphanChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 孤儿数据体检。
 *
 * 只报告、不改数据：删不删、怎么删需要人判断（比如「用户已删但部门关联残留」
 * 直接删关联没问题，而「角色授权指向已删节点」可能是权限同步漏了，先补同步更合适）。
 *
 * `--strict` 供定时任务 / CI 使用：发现孤儿数据时返回非 0。
 */
#[AsCommand('cccms:orphan-check', '体检孤儿数据（关联表残留），默认只报告不改数据')]
class OrphanCheckCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, '发现孤儿数据时返回非 0 退出码');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = OrphanChecker::scan();
        $issues = $result['issues'];

        $output->writeln("已检查 {$result['checked']} 组关联关系");

        if ($issues === []) {
            $output->writeln('<info>未发现孤儿数据</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<comment>发现以下孤儿数据（父行已被彻底删除，关联行仍残留）：</comment>');
        foreach ($issues as $issue) {
            $samples = $issue['samples'] === [] ? '' : '  示例 id：' . implode(', ', $issue['samples']);
            $output->writeln(sprintf('  %-32s %6d 行  %s%s', $issue['key'], $issue['count'], $issue['title'], $samples));
        }

        $output->writeln('');
        $output->writeln('处理建议：授权 / 关联类残留可直接删除；`role_node.node` 指向已删菜单时，');
        $output->writeln('先跑 `php webman cccms:menu-sync` + `php webman cccms:perm-scan` 再复查。');

        if ($input->getOption('strict')) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
