<?php

declare(strict_types=1);

/** Scheduled task messages. */
return [
    'expression_invalid'     => 'Invalid cron expression, or it will not trigger within the next year (six fields: second minute hour day month weekday, e.g. 0 0 */5 * * *)',
    'target_invalid'         => 'Invalid execution target: it must be a class implementing CrontabTask in code',
    'overlap_invalid'        => 'Overlap policy must be skip or allow',
    'timeout_invalid'        => 'Timeout seconds cannot be negative (0 means unlimited)',
    'retry_times_invalid'    => 'Retry times must be between 0 and 10',
    'retry_interval_invalid' => 'Retry interval must be at least 1 second',
    'group_name_too_long'    => 'Task group name cannot exceed 32 characters',
    'params_invalid'         => 'Params must be a valid JSON object',
    'not_found'              => 'Scheduled task not found',
    'no_permission'          => 'No permission to operate this scheduled task',
];
