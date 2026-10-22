<?php

/**
 * 代码生成器配置。
 *
 * 代码生成会**直接写入项目文件**（Model / Logic / Controller / 路由 / 前端页面），
 * 与「在线升级」同级风险：一旦在生产被调用，等于给了服务端任意文件写入能力。
 * 因此与 `config/upgrade.php` 保持同一套双闸口径：
 *
 *   1. 目录自检（`Environment::isDevLayout()`）：仓库根必须存在 frontend/package.json，
 *      说明是完整源码仓库；线上「已构建」形态会被自动拦住。
 *   2. 本开关：默认**关闭**，本地开发需要时显式开启。
 *
 * 两道都过才允许调用，判定入口统一为 `Environment::blockedBy('generator')`。
 * 新增危险功能时请在 `Environment::DANGEROUS` 登记，未登记的功能名会被直接拒绝。
 */
return [
    // 总开关：默认关闭，只在本地开发环境按需打开（线上不要开）
    'enable' => filter_var(getenv('CCCMS_GENERATOR_ENABLE') ?: 'false', FILTER_VALIDATE_BOOL),
];
