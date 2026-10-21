<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use think\db\PDOConnection;
use Throwable;
use Webman\Bootstrap;
use Workerman\Timer;
use Workerman\Worker;

/**
 * MySQL 连接保活（心跳）。
 *
 * 背景：workerman 是常驻进程，每个 worker 内部只持有少量 PDO 长连接。
 * 连接空闲超过 MySQL 的 wait_timeout / interactive_timeout，或被 RDS 代理、
 * SLB、云防火墙回收后，下一条 SQL 会抛 2006 (gone away) / 2013 (lost connection)。
 *
 * 做法：每隔 N 秒对「已经建立过」的连接执行一次 SELECT 1。
 * - 只探活已存在的连接，不为没用过 DB 的进程新建连接（getPdo() 为 false 直接跳过）；
 * - 探活失败只关闭连接，由下一条业务 SQL 重新建连（配合 break_reconnect 双保险）。
 *
 * 间隔用 DB_HEARTBEAT_INTERVAL 控制（秒），<= 0 表示关闭，默认 30 秒。
 */
final class DatabaseHeartbeat implements Bootstrap
{
    /** 默认探活间隔（秒）：需明显小于 MySQL wait_timeout / 中间件空闲回收时间 */
    private const DEFAULT_INTERVAL = 30;

    /** 最小间隔（秒），避免配置过小把 MySQL 打爆 */
    private const MIN_INTERVAL = 5;

    public static function start($worker): void
    {
        // CLI（`php webman xxx` 命令、测试）也会跑一遍 bootstrap，但此时没有 workerman
        // 事件循环，Timer::add() 会直接抛「Timer can only be used in workerman running
        // environment」。命令是一次性进程，本来也不需要保活，直接跳过。
        if (!$worker instanceof Worker) {
            return;
        }

        $interval = (int)(getenv('DB_HEARTBEAT_INTERVAL') ?: self::DEFAULT_INTERVAL);
        if ($interval <= 0) {
            return;
        }

        $interval = max(self::MIN_INTERVAL, $interval);

        Timer::add($interval, static function (): void {
            self::pingAll();
        });
    }

    /** 对当前进程内所有已建立的连接探活一次。 */
    private static function pingAll(): void
    {
        $names = array_keys((array)config('plugin.cccms.database.connections', []));

        foreach ($names as $name) {
            $name = (string)$name;
            $connection = self::connection($name);

            // 该进程还没用过数据库（或不是 PDO 连接）：不主动建连，避免白占连接数
            if (!$connection || !$connection->getPdo()) {
                continue;
            }

            try {
                $connection->query('SELECT 1');
            } catch (Throwable $e) {
                // 连接已死：直接关掉，等业务请求重新建连。
                // 多数情况 break_reconnect 已在 query 内部重连成功，走到这里的是兜底。
                self::closeQuietly($name);
                Log::warning('[db] heartbeat failed, connection closed: ' . $e->getMessage());
            }
        }
    }

    /** 取连接实例；think-orm 的连接对象是 PDOConnection 子类（query/getPdo 只在这里有类型声明）。 */
    private static function connection(string $name): ?PDOConnection
    {
        try {
            $connection = Database::connect($name);

            return $connection instanceof PDOConnection ? $connection : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function closeQuietly(string $name): void
    {
        try {
            Database::connect($name)->close();
        } catch (Throwable) {
            // 关不掉也无所谓：下次业务查询失败时 break_reconnect 会 close() 后重建
        }
    }
}
