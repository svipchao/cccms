<?php

declare(strict_types=1);

/**
 * 在线命令终端（F2）。
 *
 * 命令名 / 说明 / 参数名都在这里：`config/terminal.php` 只登记 key，
 * 翻译后由接口下发，前端不重复维护文案。
 */
return [
    // ---- 命令 ----
    'menu_sync'            => '同步菜单',
    'menu_sync_desc'       => '把 db/menu.php 的目录 / 菜单写进 sys_menu（幂等）',
    'perm_scan'            => '同步权限节点',
    'perm_scan_desc'       => '扫描控制器 #[Permission] 注解并写入按钮节点',
    'openapi_check'        => '接口一致性检查',
    'openapi_check_desc'   => '校验路由、权限注解、接口文档三者是否一致（退出码非 0 即不一致）',
    'data_check'           => '数据权限体检',
    'data_check_desc'      => '检查控制器是否直连库、受控表是否已模型化',
    'log_verify'           => '审计链校验',
    'log_verify_desc'      => '校验 sys_log 的链式哈希，定位被篡改 / 删除的记录（只读）',
    'log_archive'          => '归档历史日志',
    'log_archive_desc'     => '把早于 N 天的日志归档到存储后删除主库记录；建议先用 dry-run 试算',
    'db_upgrade'           => '初始化数据库结构',
    'db_upgrade_desc'      => '执行 schema.sql + seed.sql（幂等，但会改库结构与种子数据）',

    // ---- 参数 ----
    'param_from'       => '起始 ID',
    'param_to'         => '结束 ID',
    'param_days'       => '保留天数',
    'param_batch'      => '每批条数',
    'param_path'       => '仅该路径',
    'param_dry_run'    => '只统计不删除',

    // ---- 提示与错误 ----
    'disabled'         => '在线命令终端已关闭（config/terminal.php 的 enable）',
    'not_found'        => '命令不存在或已下线',
    'danger_forbidden' => '该命令需要「执行高危命令」权限',
    'confirm_required' => '危险命令需要输入 {key} 才能执行',
    'param_required'   => '参数「{name}」必填',
    'param_invalid'    => '参数「{name}」格式不正确',
    'param_range'      => '参数「{name}」超出范围（{min}~{max}）',
    'param_length'     => '参数「{name}」超长（最多 {max} 个字符）',
    'submitted'        => '命令已提交执行，稍候查看输出',
    'push_failed'      => '投递执行失败：{reason}',
    'job_not_found'    => '执行记录不存在',
    'unknown'          => '未命名命令',
    'spawn_failed'     => '无法启动命令进程（proc_open 不可用？）',
    'no_output'        => '（无输出）',
    'output_truncated' => '—— 输出超过 {limit} 字节，已截断 ——',
    'timeout'          => '—— 命令超时（{seconds}s），已终止 ——',
    'exit_code'        => '退出码 {code}',
];
