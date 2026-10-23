<?php

declare(strict_types=1);

/**
 * 第三方依赖补丁：`qiniu/php-sdk` 的 PHP 8.4 兼容（隐式可空参数）。
 *
 * 问题：SDK 7.14.0（当前最新版，上游至今未适配）仍写 `Config $config = null`
 * 这类**隐式可空**签名，PHP 8.4 在「类编译」时抛 `E_DEPRECATED`：
 *   1. 启动噪音：SDK 的 composer `files`（`src/Qiniu/functions.php`）第一行引用了
 *      `Qiniu\Config::SDK_VER`，于是每条 CLI 命令、每个 worker 启动都会打印一行
 *      Deprecated，把真正的命令输出顶到下面；
 *   2. 运行期故障：webman 的错误处理器会把「error_reporting 覆盖到的错误」转成
 *      `ErrorException`，所以 PHP 8.4 下真正使用七牛驱动时（`UploadManager` /
 *      `BucketManager` 首次加载）会直接 500。
 *
 * 处理：把构造函数里 4 处参数显式写成可空（`?Type $x = null`），语义与原来完全等价。
 *
 * 为什么改 vendor 而不是改 error_reporting：把 `E_DEPRECATED` 从 error_reporting
 * 里摘掉会连**项目自己**的弃用提示一起吞掉，等于拿可观测性换一行噪音；
 * 而这里只消除「已经不可能修」的那 4 处，其余提示照旧。
 *
 * 由 composer.json 的 `post-autoload-dump` 调用：`composer install / update /
 * dump-autoload` 之后自动重打，不会因依赖重装而回退；也可手工执行
 * `php support/vendor_patch.php`。脚本**幂等**：打过补丁就不再匹配（`?` 已在
 * 类型前），上游修复后同样自动落空。`vendor/` 不参与在线升级，补丁不会被覆盖。
 */

$root = dirname(__DIR__);

/** 待补丁文件（相对 server 目录）。七牛驱动可选，SDK 未安装时整段跳过。 */
$files = [
    'vendor/qiniu/php-sdk/src/Qiniu/Config.php',
    'vendor/qiniu/php-sdk/src/Qiniu/Storage/UploadManager.php',
    'vendor/qiniu/php-sdk/src/Qiniu/Storage/BucketManager.php',
    'vendor/qiniu/php-sdk/src/Qiniu/Storage/ArgusManager.php',
];

/**
 * 只改构造函数签名里的「类型 + 默认 null」参数，避免误伤注释 / 属性声明。
 *
 * 负向前瞻 `(?<![\w?\\])`：已经写成 `?Type` 或 `\Foo\Bar` 的不再重复加问号。
 */
$signal = '/(?<![\w?\\\\])([A-Z][A-Za-z0-9_]*)(\s+)(\$\w+)(\s*)=(\s*)null/';

$patchedFiles = 0;
$patchedParams = 0;

foreach ($files as $relative) {
    $file = $root . '/' . $relative;
    if (!is_file($file)) {
        continue;
    }

    $source = (string)file_get_contents($file);
    $count = 0;

    $next = preg_replace_callback(
        '/function\s+__construct\s*\([^)]*\)/s',
        static function (array $match) use ($signal, &$count): string {
            return (string)preg_replace($signal, '?$1 $3 = null', $match[0], -1, $count);
        },
        $source
    );

    if (!is_string($next) || $next === $source) {
        continue;
    }

    file_put_contents($file, $next);
    $patchedFiles++;
    $patchedParams += $count;
}

if ($patchedParams > 0) {
    echo "  qiniu/php-sdk：已补 {$patchedFiles} 个文件的 {$patchedParams} 处隐式可空参数（PHP 8.4）", PHP_EOL;
}
