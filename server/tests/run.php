<?php

declare(strict_types=1);

/**
 * CCCMS 后端测试运行器（零依赖）。
 *
 * 没有引入 PHPUnit：本项目的测试对象是**纯逻辑与少量需要数据库的判定**
 * （Cron 匹配、权限节点规范、CSV、数据规则体检），一个 200 行的运行器足够，
 * 也避免 CI 里多一层依赖。需要 PHPUnit 的高级特性（mock / dataProvider）时再迁移。
 *
 * 用法：
 *   php tests/run.php            # 全部
 *   php tests/run.php Cron       # 只跑名字包含 Cron 的用例
 *
 * 需要数据库的用例在数据库不可用时**自动跳过**（本地无库也能跑）。
 * 退出码：有失败 → 1，否则 0（可直接用于 CI）。
 *
 * **CI 必须让集成用例真跑**：设 `CCCMS_TEST_REQUIRE_SERVICES=1` 后，
 * 「跳过」会升级为「失败」——否则 CI 里服务没起好时用例会静默全绿，
 * 安全链路（登录双维度锁定、租户边界）等于没有回归保护。
 */

require __DIR__ . '/../vendor/autoload.php';

use think\facade\Db;

// 审计链 v2 起用 HMAC-SHA256，写入需要签名密钥。测试环境固定一个：
// 不设的话 `LogChain::write()` 会走「降级普通插入」分支，链相关用例全部失效
// （表现为「row_hash 应写入」失败，而不是报错，很容易被误读成库结构问题）。
// 生产环境由 `SecurityBootstrap` 强制要求真实密钥，未配置直接拒绝启动。
putenv('AUDIT_SIGNING_KEY=cccms-test-only-signing-key');

// 初始化 think-orm 连接（与 webman worker 启动时 DatabaseBootstrap 做的事一致）。
// 测试运行器不经过 webman 的启动流程，这里显式注入，让「需库」的集成测试
// 在 CI（MySQL 服务就绪）下真正执行，而不是永远跳过；本地无库时由 dbAvailable() 自动跳过。
$dbConfigFile = __DIR__ . '/../plugin/cccms/config/database.php';
if (is_file($dbConfigFile)) {
    Db::setConfig((array)require $dbConfigFile);
}

// 加载 webman 配置仓库，让 support\Redis 在 CLI 下与 worker 启动行为一致
// （依赖 Redis 的用例因此能真正执行，而不是永远跳过）。Redis 服务不可用时
// 相关用例会自行降级，其余用例不受影响。
try {
    \Webman\Config::load(__DIR__ . '/../config');
} catch (Throwable) {
    // 配置加载失败：Redis 相关用例自行跳过
}

final class Suite
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var array<int,string> */
    public static array $failures = [];
    public static string $group = '';
    /** @var string|null 过滤器（命令行参数） */
    public static ?string $filter = null;
    private static ?bool $dbReady = null;

    public static function dbAvailable(): bool
    {
        if (self::$dbReady !== null) {
            return self::$dbReady;
        }

        try {
            Db::query('SELECT 1');
            self::$dbReady = true;
        } catch (Throwable) {
            self::$dbReady = false;
        }

        return self::$dbReady;
    }

    public static function color(string $text, string $color): string
    {
        // Windows 老终端不支持 ANSI，简单探测后回退为无色
        if (DIRECTORY_SEPARATOR === '\\' && getenv('ANSICON') === false && getenv('WT_SESSION') === false) {
            return $text;
        }

        $map = ['green' => '32', 'red' => '31', 'yellow' => '33', 'cyan' => '36', 'gray' => '90'];

        return "\033[" . ($map[$color] ?? '0') . 'm' . $text . "\033[0m";
    }
}

function suite(string $name): void
{
    Suite::$group = $name;
    echo "\n" . Suite::color('■ ' . $name, 'cyan') . "\n";
}

/**
 * 依赖服务是否**必须**可用：CI 里设 `CCCMS_TEST_REQUIRE_SERVICES=1`，
 * 此时「跳过」按失败处理（详见文件头说明）。
 */
function requireServices(): bool
{
    $raw = strtolower((string)getenv('CCCMS_TEST_REQUIRE_SERVICES'));

    return in_array($raw, ['1', 'true', 'on', 'yes'], true);
}

/** 跳过一条需要依赖服务的用例：要求服务时记为失败，否则记为跳过 */
function skipNeedsService(string $name, string $reason): void
{
    if (requireServices()) {
        Suite::$failed++;
        Suite::$failures[] = "[{$name}] 依赖服务不可用（{$reason}），而本次运行要求服务必须可用";

        echo '  ' . Suite::color('✗', 'red') . " {$name}（{$reason}，本次要求服务必须可用）\n";

        return;
    }

    Suite::$skipped++;
    echo '  ' . Suite::color('○ 跳过', 'gray') . " {$name}（{$reason}）\n";
}

/**
 * 注册一个用例。
 *
 * @param callable():void $fn
 * @param bool            $requiresDb 需要数据库；不可用时跳过
 */
function test(string $name, callable $fn, bool $requiresDb = false): void
{
    if (Suite::$filter !== null && stripos($name, Suite::$filter) === false && stripos(Suite::$group, Suite::$filter) === false) {
        return;
    }

    if ($requiresDb && !Suite::dbAvailable()) {
        skipNeedsService($name, '数据库不可用');
        return;
    }

    try {
        $fn();
        Suite::$passed++;
        echo '  ' . Suite::color('✓', 'green') . " {$name}\n";
    } catch (Throwable $e) {
        Suite::$failed++;
        Suite::$failures[] = "[{$name}] " . $e->getMessage();
        echo '  ' . Suite::color('✗', 'red') . " {$name}\n";
        echo '      ' . Suite::color($e->getMessage(), 'red') . "\n";
    }
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function ok(bool $condition, string $message = '断言失败'): void
{
    if (!$condition) {
        fail($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ' — ' : '')
            . '期望 ' . var_export($expected, true) . '，实际 ' . var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ' — ' : '') . '未找到 ' . var_export($needle, true));
    }
}

// ---------------------------------------------------------------------
// 载入用例文件（每个文件返回一个注册闭包）
// ---------------------------------------------------------------------
$filter = $argv[1] ?? null;
Suite::$filter = $filter !== null && $filter !== '' ? $filter : null;

$files = glob(__DIR__ . '/cases/*.php') ?: [];
sort($files);

foreach ($files as $file) {
    $register = require $file;
    if (is_callable($register)) {
        $register();
    }
}

echo "\n" . str_repeat('-', 56) . "\n";
echo sprintf(
    "通过 %s，失败 %s，跳过 %s%s\n",
    Suite::color((string)Suite::$passed, 'green'),
    Suite::$failed > 0 ? Suite::color((string)Suite::$failed, 'red') : '0',
    (string)Suite::$skipped,
    $filter ? "（过滤：{$filter}）" : ''
);

if (Suite::$failed > 0) {
    echo "\n" . Suite::color('失败详情：', 'red') . "\n";
    foreach (Suite::$failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    exit(1);
}

// 要求依赖服务可用时，「跳过」也视为失败：CI 里服务没起好不该静默全绿
if (requireServices() && Suite::$skipped > 0) {
    echo "\n" . Suite::color(
        '本次运行要求依赖服务必须可用，但有 ' . Suite::$skipped . ' 条用例被跳过（MySQL / Redis 没起好？）',
        'red'
    ) . "\n";
    exit(1);
}

exit(0);
