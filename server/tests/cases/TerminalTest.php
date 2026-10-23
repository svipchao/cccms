<?php

declare(strict_types=1);

use plugin\cccms\support\Terminal;

/**
 * 在线命令终端（F2）。
 *
 * 只测**纯逻辑**（命令表 / 参数校验 / argv 构建），不真的执行命令：
 *   - 执行结果依赖部署环境，跑测试的机器不该被 `cccms:log-archive` 之类改数据；
 *   - 真正的风险点在「参数怎么进 argv」，把这一层测死就守住了注入面。
 *
 * 重点是几条**防回归**断言：
 *   - 命令表里不得出现 `sh -c` / `cmd /c`（那等于把 shell 请回来）；
 *   - 危险命令的 risk 不得被误降级；
 *   - 未声明的参数、含分号 / 换行 / NUL 的值必须被拒绝。
 */
return static function (): void {
    suite('在线命令终端（F2）');

    test('命令表：key 唯一、risk 合法、文案已翻译', function (): void {
        $commands = Terminal::commands();
        ok(count($commands) >= 5, '至少应登记 5 条命令，实际 ' . count($commands));

        $keys = array_column($commands, 'key');
        same(array_values(array_unique($keys)), $keys, '命令 key 必须唯一');

        foreach ($commands as $command) {
            $key = (string)$command['key'];
            ok(
                preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) === 1,
                "命令 key 不合法：{$key}"
            );
            ok(
                in_array((int)$command['risk'], [1, 2, 3], true),
                "命令 risk 越界：{$key}"
            );
            ok(
                !str_starts_with((string)$command['label'], 'terminal.'),
                "命令缺少翻译：{$key}"
            );
            ok(
                !str_starts_with((string)$command['description'], 'terminal.'),
                "命令说明缺少翻译：{$key}"
            );

            foreach ((array)$command['params'] as $param) {
                ok(
                    in_array($param['type'], ['int', 'bool', 'text'], true),
                    "参数类型不合法：{$key}.{$param['name']}"
                );
                ok(
                    !str_starts_with((string)$param['label'], 'terminal.'),
                    "参数缺少翻译：{$key}.{$param['name']}"
                );
            }
        }
    });

    test('命令表：不得经 shell 执行', function (): void {
        $shells = ['sh', 'bash', 'dash', 'zsh', 'cmd', 'cmd.exe', 'powershell', 'powershell.exe'];

        foreach (Terminal::commands() as $command) {
            $argv = Terminal::argv($command, []);
            ok($argv !== [], "命令 {$command['key']} 的 argv 不能为空");

            $head = strtolower(basename((string)$argv[0]));
            ok(!in_array($head, $shells, true), "命令 {$command['key']} 不得经 shell 执行（argv[0]={$head}）");
            ok(!in_array('-c', $argv, true), "命令 {$command['key']} 的 argv 不得包含 -c");
            ok(!in_array('/c', $argv, true), "命令 {$command['key']} 的 argv 不得包含 /c");
        }
    });

    test('命令表：破坏性命令的 risk 不得被降级', function (): void {
        // 归档会删主库记录、db-upgrade 会改库结构：这两条一旦被改成 1，前端就不再要求二次确认
        same(2, Terminal::find('log_archive')['risk'] ?? 0, 'log_archive 必须是 risk 2');
        same(3, Terminal::find('db_upgrade')['risk'] ?? 0, 'db_upgrade 必须是 risk 3');
    });

    test('argv：占位符替换 / int 与 bool 的拼法', function (): void {
        $command = Terminal::find('log_verify');
        ok($command !== null, 'log_verify 应存在');

        $argv = Terminal::argv($command, ['from' => 100]);
        same(PHP_BINARY, $argv[0], '{php} 应替换为当前解释器');
        ok(str_ends_with($argv[1], 'webman'), '{root} 应替换为项目根');
        ok(in_array('--from=100', $argv, true), 'int 参数应拼成 --name=value');
        ok(!in_array('--to=', $argv, true), '未提交的可选参数不该出现');

        $archive = Terminal::find('log_archive');
        ok($archive !== null, 'log_archive 应存在');
        ok(
            in_array('--dry-run', Terminal::argv($archive, ['dry_run' => true]), true),
            'bool 参数为 true 时应拼成 flag'
        );
        ok(
            !in_array('--dry-run', Terminal::argv($archive, ['dry_run' => false]), true),
            'bool 参数为 false 时不该出现'
        );
    });

    test('参数校验：int 的范围与注入字符', function (): void {
        $command = Terminal::find('log_verify');
        ok($command !== null, 'log_verify 应存在');

        $reject = static function (callable $fn, string $message): void {
            try {
                $fn();
            } catch (Throwable) {
                return;
            }
            fail($message);
        };

        same(['from' => 10], Terminal::validate($command, ['from' => '10']), '数字字符串应归一化为 int');
        same([], Terminal::validate($command, ['from' => '', 'unknown' => 'x']), '空值与未声明键都应被丢弃');

        foreach (['abc', '1;rm -rf /', "1\n2", '1e3', '-1', '0', '99999999999999'] as $bad) {
            $reject(
                static fn () => Terminal::validate($command, ['from' => $bad]),
                "应拒绝非法参数：{$bad}"
            );
        }
    });

    test('参数校验：bool 只认真假写法', function (): void {
        $command = Terminal::find('log_archive');
        ok($command !== null, 'log_archive 应存在');

        $reject = static function (callable $fn, string $message): void {
            try {
                $fn();
            } catch (Throwable) {
                return;
            }
            fail($message);
        };

        same(['dry_run' => true], Terminal::validate($command, ['dry_run' => '1']), "'1' 应解析为 true");
        same(['dry_run' => true], Terminal::validate($command, ['dry_run' => true]), 'true 应保留');
        same(['dry_run' => false], Terminal::validate($command, ['dry_run' => 'false']), "'false' 应解析为 false");
        same([], Terminal::validate($command, ['dry_run' => '']), '空值视为未提交');

        $reject(static fn () => Terminal::validate($command, ['dry_run' => 'maybe']), '应拒绝模糊的布尔值');
        $reject(static fn () => Terminal::validate($command, ['dry_run' => '--dry-run']), '应拒绝把 flag 当值传');
    });

    test('参数校验：text 的正则与长度', function (): void {
        $command = Terminal::find('log_archive');
        ok($command !== null, 'log_archive 应存在');

        $reject = static function (callable $fn, string $message): void {
            try {
                $fn();
            } catch (Throwable) {
                return;
            }
            fail($message);
        };

        same(['path' => '/auth/login'], Terminal::validate($command, ['path' => '/auth/login']), '合法路径应通过');
        $reject(static fn () => Terminal::validate($command, ['path' => '/auth/login;rm -rf /']), '含分号应拒绝');
        $reject(static fn () => Terminal::validate($command, ['path' => 'auth/login']), '缺少前导斜杠应拒绝');
        $reject(static fn () => Terminal::validate($command, ['path' => '/' . str_repeat('a', 200)]), '超长应拒绝');
    });
};
