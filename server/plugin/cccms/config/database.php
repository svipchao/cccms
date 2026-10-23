<?php

// think-orm 连接配置。可通过环境变量覆盖（部署时注入）。
return [
    'default'     => 'mysql',
    'connections' => [
        'mysql' => [
            'type'     => 'mysql',
            'hostname' => getenv('DB_HOST') ?: '127.0.0.1',
            'database' => getenv('DB_DATABASE') ?: 'cccms',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '123456',
            'hostport' => getenv('DB_PORT') ?: '3306',
            'charset'  => 'utf8mb4',
            'prefix'   => 'sys_',
            // 必须用 filter_var：`(bool)'false'` 为 true，会让按示例配置的部署意外开启 SQL 调试
            'debug'    => (bool)filter_var(getenv('DB_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            'deploy'   => 0,
            'rw_separate' => false,
            'fields_strict' => true,
            'auto_timestamp' => 'datetime',

            // ---- 长连接容错（workerman 常驻进程必配）----
            // 进程内的 PDO 连接是长连接：空闲超过 MySQL 的 wait_timeout（或中间件
            // ——RDS 代理 / SLB / 防火墙回收空闲 TCP）之后，下一条 SQL 会抛
            // 「SQLSTATE[HY000]: General error: 2006 MySQL server has gone away」。
            // 打开后 think-orm 会在「非事务」的单条 SQL 上自动 close + 重连并重试
            // （最多 4 次），业务侧基本无感知；事务内的语句不重试，避免事务状态错乱。
            'break_reconnect' => true,
            // 追加断线特征串（内置已含 server has gone away / Lost connection 等，这里是双保险）
            'break_match_str' => ['MySQL server has gone away', 'error 2006'],
            // PDO 建连超时（秒）：MySQL 抖动 / 网络不通时快速失败，不把 worker 挂死
            'params' => [
                \PDO::ATTR_TIMEOUT => (int)(getenv('DB_CONNECT_TIMEOUT') ?: 5),
            ],
        ],
    ],
];
