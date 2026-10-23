<?php

declare(strict_types=1);

namespace plugin\cccms\command;

use plugin\cccms\support\OpenApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 接口文档门禁：路由 ↔ 权限注解 ↔ 文档三者必须对得上（F1）。
 *
 * 校验项见 `OpenApi::check()`；这里只负责输出与退出码。
 * 接入 CI 时用 `--json`，避免被表格格式变化影响解析。
 */
#[AsCommand('cccms:openapi-check', '校验路由与权限注解是否一致（接口文档门禁）')]
class OpenApiCheckCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, '以 JSON 输出，供 CI 解析');
        $this->addOption('stat', null, InputOption::VALUE_NONE, '只打印统计（分组 / 接口 / 未声明权限数）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operations = OpenApi::operations();
        $errors = OpenApi::check();
        $groups = OpenApi::tree();

        $stats = [
            'groups' => count($groups),
            'operations' => count($operations),
            // 无需鉴权的接口单独计数：这些是公开接口，改动时要额外当心
            'public' => count(array_filter(
                $operations,
                static fn (array $op): bool => $op['noLogin'] || $op['noAuth']
            )),
            'unbounded' => count(array_filter(
                $operations,
                static fn (array $op): bool => (bool)$op['unbounded']
            )),
            'errors' => count($errors),
        ];

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode(
                ['stats' => $stats, 'errors' => $errors],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $errors === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $table = new Table($output);
        $table->setHeaders(['分组', '接口数'])
            ->setRows(array_map(
                static fn (array $g): array => [$g['title'], (string)count($g['operations'])],
                $groups
            ));
        if (!$input->getOption('stat')) {
            $table->render();
        }

        $output->writeln(sprintf(
            '共 <info>%d</info> 个分组 / <info>%d</info> 个接口，其中公开接口 %d 个'
            . '（整包透传、参数无法枚举的 %d 个）',
            $stats['groups'],
            $stats['operations'],
            $stats['public'],
            $stats['unbounded']
        ));

        foreach ($errors as $error) {
            $output->writeln("<error>{$error}</error>");
        }

        if ($errors !== []) {
            $output->writeln('<error>校验失败，共 ' . count($errors) . ' 处不一致</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>校验通过：路由、权限注解、接口文档三者一致</info>');

        return Command::SUCCESS;
    }
}
