<?php

declare(strict_types=1);

/** 异步作业队列（F5）文案。 */
return [
    'target_invalid' => '执行目标非法：必须是在代码中实现 JobHandler 的类（{target}）',
    'not_found'      => '作业不存在',
    'already_done'   => '该作业已完成，无需重试',
    'retried'        => '已重新入队，稍后会被消费',
    'removed'        => '已删除 {count} 条作业',
    'remove_none'    => '所选作业尚未结束（待执行 / 执行中），不能删除',
];
