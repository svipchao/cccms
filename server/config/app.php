<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Request;

return [
    // 应用版本（**唯一字面量**，P2-1）：发布时只改这一处，`docs/更新日志.md` 记录对应条目，
    // `tests/cases/VersionTest.php` 断言它与 `frontend/package.json` 一致。
    'version' => '0.1.0',
    // 安全默认：生产环境不得透出异常详情，故默认关闭。
    // 本地开发在 server/.env 中设置 APP_DEBUG=true（见 .env.example）。
    'debug' => (bool)filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'error_reporting' => E_ALL,
    'default_timezone' => 'Asia/Shanghai',
    'request_class' => Request::class,
    'public_path' => base_path() . DIRECTORY_SEPARATOR . 'public',
    'runtime_path' => base_path(false) . DIRECTORY_SEPARATOR . 'runtime',
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
];
