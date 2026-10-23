<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use think\db\ConnectionInterface;
use think\facade\Db;

/**
 * think-orm 访问入口（薄封装，统一走 Facade）。
 *
 * 用法：
 *   Database::connect()->name('user')->select();
 *
 * 历史上有 `table()` 便捷方法，但全仓没有调用方（各处直接 `Db::name()`），
 * 留着等于给「绕开模型层」提供第二条入口，已删除。
 */
final class Database
{
    /** 获取连接。 */
    public static function connect(string $name = 'mysql'): ConnectionInterface
    {
        return Db::connect($name);
    }
}
