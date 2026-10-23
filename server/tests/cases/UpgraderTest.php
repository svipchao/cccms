<?php

declare(strict_types=1);

use plugin\cccms\support\Upgrader;

/**
 * 升级器（`cccms:update`）的**安全边界**用例。
 *
 * 完整 `plan()/apply()` 需要真实 git 仓库与上游差异，不适合放进单元测试；
 * 但「哪些文件永不被覆盖」「日志里会不会泄露令牌」这两件事是**纯函数**，
 * 也正是升级功能最不能出错的地方 —— 在这里钉死。
 */
return static function (): void {
    suite('升级器安全边界（Upgrader）');

    test('排除清单：密钥 / 依赖 / 运行时 / 构建产物永不被上游覆盖', function (): void {
        // 命中的应当被排除
        foreach ([
            'server/.env',
            'server/vendor/autoload.php',
            'server/runtime/logs/app.log',
            'frontend/node_modules/vue/package.json',
            'frontend/dist/index.html',
            'frontend/.env.local',
            '.git/config',
        ] as $path) {
            ok(Upgrader::excluded($path), "{$path} 应被排除");
            ok(!Upgrader::accepts($path), "{$path} 不应进入同步清单");
        }

        // 不能误伤：同前缀但不是子路径 / 只是名字相近
        foreach ([
            'server/.env.example',
            'server/vendor_patch.php',
            'server/runtime.php',
            'frontend/.env.example',
            'server/app/controller/UserController.php',
        ] as $path) {
            ok(!Upgrader::excluded($path), "{$path} 不应被排除（前缀必须按目录边界匹配）");
        }
    });

    test('同步范围：include 为空表示全仓库（排除项仍生效）', function (): void {
        ok(Upgrader::accepts('server/plugin/cccms/support/Upgrader.php'), '普通源码文件应进入同步范围');
        ok(Upgrader::accepts('README.md'), '根目录文件也应进入同步范围');
        ok(!Upgrader::accepts(''), '空路径不同步');
    });

    test('摘要：文本行尾归一（CRLF 与 LF 视为同一内容），二进制原样哈希', function (): void {
        $lf   = "a\nb\n";
        $crlf = "a\r\nb\r\n";
        same(Upgrader::digest($lf), Upgrader::digest($crlf), '行尾差异不应被判成「本地改过」');

        $binary = "a\0b";
        ok(Upgrader::digest($binary) !== Upgrader::digest(str_replace("\0", '', $binary)), '含 NUL 的内容不走行尾归一');

        same(null, Upgrader::digestFile(Upgrader::root() . '/__not_exists__'), '文件不存在返回 null');
    });

    test('凭据脱敏：URL 里的 userinfo 被替换，但 git@host:path 不受影响', function (): void {
        same(
            'https://***@gitee.com/poetryiii/cccms.git',
            Upgrader::maskCredentials('https://user:token@gitee.com/poetryiii/cccms.git'),
            '账号密码形式应整体脱敏'
        );
        same(
            'https://***@github.com/x/y.git',
            Upgrader::maskCredentials('https://ghp_abcdef@github.com/x/y.git'),
            '仅令牌形式也应脱敏'
        );
        same(
            'clone git@github.com:poetryiii/cccms.git failed',
            Upgrader::maskCredentials('clone git@github.com:poetryiii/cccms.git failed'),
            'SSH 形式没有 ://，不能被误伤'
        );
        same(
            'pull https://***@a.com/r.git and https://***@b.com/r.git',
            Upgrader::maskCredentials('pull https://u:p@a.com/r.git and https://tok@b.com/r.git'),
            '一段文本里的多个 URL 都要处理'
        );
    });

    test('路径换算：path / relative 互逆，且越界路径原样返回', function (): void {
        $root = Upgrader::root();
        same($root . '/README.md', Upgrader::path('README.md'), '相对路径补上仓库根');
        same('README.md', Upgrader::relative($root . '/README.md'), '绝对路径去掉仓库根');
        same(
            str_replace('\\', '/', 'D:/outside/file.txt'),
            Upgrader::relative('D:/outside/file.txt'),
            '仓库外的路径原样返回，不能被误当成仓库内文件'
        );
    });
};
