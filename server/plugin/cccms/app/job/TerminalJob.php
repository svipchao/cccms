<?php

declare(strict_types=1);

namespace plugin\cccms\app\job;

use plugin\cccms\support\I18n;
use plugin\cccms\support\JobHandler;
use plugin\cccms\support\Terminal;
use RuntimeException;

/**
 * 在线终端命令的执行作业（F2）。
 *
 * ## 为什么再校验一次
 *
 * 投递时 `TerminalLogic` 已经校验过命令与参数，但作业的 payload 是**库里的 JSON**：
 * 有人能改库就能改参数。执行前重跑一遍 `Terminal::validate()`，让「库里被改过的参数」
 * 在进入 argv 之前就被拒绝 —— 队列作业是唯一真正 `proc_open` 的地方，防线要设在这里。
 *
 * ## 为什么失败时抛异常
 *
 * 退出码非 0 意味着命令失败（校验不通过、依赖不可用等）。抛异常后作业进「失败待重试」，
 * 但由于投递时 `max_attempts = 1`，它会直接进死信 —— 命令带副作用，自动重跑可能比不跑更糟。
 * 人工确认原因后可在「任务中心」重试。
 */
final class TerminalJob implements JobHandler
{
    public function handle(array $payload): string
    {
        $key     = (string)($payload['key'] ?? '');
        $command = Terminal::find($key);
        if ($command === null) {
            // 命令被下线 / 改名：这不是「重试能好」的问题，直接失败让人看见
            throw new RuntimeException(I18n::t('terminal.not_found') . '：' . $key);
        }

        $params = Terminal::validate($command, (array)($payload['params'] ?? []));
        $result = Terminal::execute($command, $params);

        $body = '`$ ' . implode(' ', Terminal::argv($command, $params)) . "`\n"
            . ($result['output'] !== '' ? $result['output'] : I18n::t('terminal.no_output'));

        if ($result['exit_code'] !== 0) {
            // 异常消息会进 `sys_job.last_error`（截 500）与 `sys_job_log.output`（截 2000），
            // 因此把输出带上（截到 1800），排错时不用再翻服务器日志
            throw new RuntimeException(
                I18n::t('terminal.exit_code', ['code' => $result['exit_code']]) . "\n"
                . mb_substr($body, 0, 1800)
            );
        }

        return I18n::t('terminal.exit_code', ['code' => 0])
            . "（{$result['cost']}ms）\n" . $body;
    }
}
