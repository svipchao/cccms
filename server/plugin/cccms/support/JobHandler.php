<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 异步作业契约（F5）。
 *
 * 与 `CrontabTask` 同一套安全思路：队列进程只执行**实现本接口的类**，
 * 因此「哪些代码能被后台投递执行」完全由代码决定 —— 数据库里存的类名
 * 无法指向任意方法或任意类（杜绝「改一行数据就能执行任意代码」）。
 *
 * 与 `CrontabTask` 的区别只在语义：
 *   - `CrontabTask::run()` 被调度器按时间反复调用，应自己保证幂等；
 *   - `JobHandler::handle()` 是**一次性**的（可能带重试），`$payload` 就是这条作业的全部输入。
 *
 * 约定：**不要**在 `handle()` 里吞异常。抛出来才会进重试 / 死信，
 * 吞掉会让作业「看起来成功」但实际什么都没做。
 */
interface JobHandler
{
    /**
     * 执行一条作业。
     *
     * @param array<string,mixed> $payload 投递时写入的参数
     * @return string 输出摘要（写入 sys_job_log.output，最长保留 2000 字符）
     */
    public function handle(array $payload): string;
}
