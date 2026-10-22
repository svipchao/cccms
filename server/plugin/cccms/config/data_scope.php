<?php

declare(strict_types=1);

/**
 * 数据权限 / 租户隔离的检查配置（`cccms:data-scope-check`，P1-1 方案 B）。
 *
 * 这里只放**检查口径**，不放权限规则：规则在 `sys_data_rule` / `sys_data_scope_table`。
 */
return [
    // 业务插件表豁免名单：不带全局前缀的**完整表名**，英文逗号分隔。
    //
    // 被豁免的表即便「没登记受控表、也没有 tenant_id 列」也不会被 R3 报错。
    // 只用于确实不需要隔离的表（纯字典、归档、外部同步游标等）—— 豁免等于
    // **明确接受「跨租户可见」**，评审时应能看到这一行。
    'exempt_tables' => array_values(array_filter(
        array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string)(getenv('DATA_SCOPE_EXEMPT_TABLES') ?: ''))
        ),
        static fn (string $value): bool => $value !== ''
    )),
];
