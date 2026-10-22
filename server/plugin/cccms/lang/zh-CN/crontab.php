<?php

declare(strict_types=1);

/** 定时任务相关文案。 */
return [
    'expression_invalid'     => 'cron 表达式非法，或未来一年内不会触发（六段：秒 分 时 日 月 周，如 0 0 */5 * * *）',
    'target_invalid'         => '执行目标非法：必须是在代码中实现 CrontabTask 的类',
    'overlap_invalid'        => '重叠策略只能是 skip（跳过）或 allow（允许并发）',
    'timeout_invalid'        => '超时秒数不能为负数（0 表示不限）',
    'retry_times_invalid'    => '失败重试次数需在 0~10 之间',
    'retry_interval_invalid' => '重试间隔至少 1 秒',
    'group_name_too_long'    => '任务分组不能超过 32 个字符',
    'params_invalid'         => '参数必须是合法的 JSON 对象',
    'not_found'              => '定时任务不存在',
    'no_permission'          => '无权操作该定时任务',
];
