<?php

declare(strict_types=1);

/**
 * 在线命令终端（F2）：**白名单命令登记表**。
 *
 * ## 为什么命令写死在 PHP 配置里
 *
 * 终端等于「把服务器的一部分执行权搬进后台」，任何能让使用者自由输入命令的设计
 * （哪怕是「只允许某几个前缀」）都等价于把后台当成 shell。这里只接受**代码里登记过的
 * 固定 argv**：使用者能选的只有命令 `key`，能填的只有声明过的参数，取值还要过类型/范围/正则。
 *
 * ## 字段
 *
 * - `key`：全局唯一标识，前端与接口都只认它；同时是二次确认要手输的字符串。
 * - `label` / `description`：语言包 key（`terminal.*`），后端翻译后下发。
 * - `group`：前端分组（sync / check / data）。
 * - `risk`：1 普通 · 2 危险（删数据 / 归档）· 3 高危（改库结构）。`>= 2` 需要
 *   `cccms:terminal:danger` 权限 **且** 请求里带 `confirm` 与 key 完全一致。
 * - `argv`：**数组**，第一个元素是解释器。`{php}` / `{root}` 在运行时替换，
 *   绝不用 shell 字符串，也不做任何引号拼接（见 `Terminal::argv()`）。
 * - `params`：参数白名单。`type` 支持 `int`（min/max）、`bool`（flag）、`text`（pattern/max_length）。
 *
 * ## 新增命令的自查
 *
 * 1. 命令必须**幂等或可预期**：作业失败不自动重试（`max_attempts = 1`），
 *    因为带副作用的命令重跑一次可能比不跑更糟。
 * 2. 有破坏性的命令一律 `risk >= 2`，并优先提供 `--dry-run` 这类只读参数。
 * 3. `argv` 里不要放 `sh -c` / `cmd /c` —— 那等于把 shell 又请回来了。
 */
return [
    // 总开关：关掉后接口返回 403，命令也不会被调度（改完需要 `php webman reload`）
    'enable'       => true,

    // 单条命令的超时（秒）：超时即终止进程，避免一个卡住的命令占住队列消费
    'timeout'      => 120,

    // 输出上限（字节）：超出部分丢弃并标注 truncated，防止把作业日志表写爆
    'output_limit' => 8000,

    'commands' => [
        [
            'key'         => 'menu_sync',
            'label'       => 'terminal.menu_sync',
            'description' => 'terminal.menu_sync_desc',
            'group'       => 'sync',
            'risk'        => 1,
            'argv'        => ['{php}', '{root}/webman', 'cccms:menu-sync'],
            'params'      => [],
        ],
        [
            'key'         => 'perm_scan',
            'label'       => 'terminal.perm_scan',
            'description' => 'terminal.perm_scan_desc',
            'group'       => 'sync',
            'risk'        => 1,
            'argv'        => ['{php}', '{root}/webman', 'cccms:perm-scan'],
            'params'      => [],
        ],
        [
            'key'         => 'openapi_check',
            'label'       => 'terminal.openapi_check',
            'description' => 'terminal.openapi_check_desc',
            'group'       => 'check',
            'risk'        => 1,
            'argv'        => ['{php}', '{root}/webman', 'cccms:openapi-check'],
            'params'      => [],
        ],
        [
            'key'         => 'data_check',
            'label'       => 'terminal.data_check',
            'description' => 'terminal.data_check_desc',
            'group'       => 'check',
            'risk'        => 1,
            'argv'        => ['{php}', '{root}/webman', 'cccms:data-scope-check'],
            'params'      => [],
        ],
        [
            // 审计链校验是只读的：可以按 id 区间缩小范围，避免全表扫
            'key'         => 'log_verify',
            'label'       => 'terminal.log_verify',
            'description' => 'terminal.log_verify_desc',
            'group'       => 'check',
            'risk'        => 1,
            'argv'        => ['{php}', '{root}/webman', 'cccms:log-verify'],
            'params'      => [
                [
                    'name'     => 'from',
                    'label'    => 'terminal.param_from',
                    'type'     => 'int',
                    'min'      => 1,
                    'max'      => 4294967295,
                    'required' => false,
                ],
                [
                    'name'     => 'to',
                    'label'    => 'terminal.param_to',
                    'type'     => 'int',
                    'min'      => 1,
                    'max'      => 4294967295,
                    'required' => false,
                ],
            ],
        ],
        [
            // 归档会**删主库记录**（先存后删）：默认风险等级 2，且保留 dry-run
            'key'         => 'log_archive',
            'label'       => 'terminal.log_archive',
            'description' => 'terminal.log_archive_desc',
            'group'       => 'data',
            'risk'        => 2,
            'argv'        => ['{php}', '{root}/webman', 'cccms:log-archive'],
            'params'      => [
                [
                    'name'     => 'days',
                    'label'    => 'terminal.param_days',
                    'type'     => 'int',
                    'min'      => 7,
                    'max'      => 3650,
                    'required' => false,
                ],
                [
                    'name'     => 'batch',
                    'label'    => 'terminal.param_batch',
                    'type'     => 'int',
                    'min'      => 100,
                    'max'      => 10000,
                    'required' => false,
                ],
                [
                    'name'        => 'path',
                    'label'       => 'terminal.param_path',
                    'type'        => 'text',
                    'pattern'     => '#^/[A-Za-z0-9_\-/]*$#',
                    'max_length'  => 120,
                    'required'    => false,
                ],
                [
                    'name'     => 'dry_run',
                    'label'    => 'terminal.param_dry_run',
                    'type'     => 'bool',
                    'required' => false,
                ],
            ],
        ],
        [
            // 改库结构：高危，必须手输 key 确认
            'key'         => 'db_upgrade',
            'label'       => 'terminal.db_upgrade',
            'description' => 'terminal.db_upgrade_desc',
            'group'       => 'data',
            'risk'        => 3,
            'argv'        => ['{php}', '{root}/webman', 'cccms:db-upgrade'],
            'params'      => [],
        ],
    ],
];
