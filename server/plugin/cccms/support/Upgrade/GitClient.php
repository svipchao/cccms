<?php

declare(strict_types=1);

namespace plugin\cccms\support\Upgrade;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\Upgrader;
use Throwable;

/**
 * git 命令层：执行、版本探测、ref 解析与 diff 统计。
 *
 * 从 `Upgrader` 拆出来的原因：这些方法只有「调 git、解析输出」两件事，与
 * 「比对三方哈希、决定覆盖哪个文件」完全无关。混在一起时，任何一次同步逻辑调整
 * 都要在一个 1300 行的文件里翻找 git 参数，而 git 参数的坑（行尾、中文路径、交互提示）
 * 恰恰是最需要一眼看全的部分。
 *
 * 配置里的 git 可执行文件统一取自 `Upgrader::settings()['git']`，不在各处重复读取。
 */
final class GitClient
{
    /**
     * 执行 git 命令。
     *
     * 统一关掉 `core.autocrlf` / `core.eol`：否则 Windows 上 clone 出的工作区是 CRLF，
     * 与基线哈希（按 LF 统一）对不上，全部文件都会被误判成「本地改过」。
     *
     * @param  array<int,string> $args
     * @return array{0:int,1:string,2:string} [退出码, stdout, stderr]
     */
    public static function run(array $args, ?string $cwd = null): array
    {
        $bin = (string)Upgrader::settings()['git'];
        $cmd = array_merge(
            [
                $bin,
                '-c', 'core.autocrlf=false',
                '-c', 'core.eol=lf',
                // 中文文件名不能被转义成 \xxx，否则 diffStat 的 path 与 ls-tree 的 path 对不上
                '-c', 'core.quotepath=false',
            ],
            $args
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // 无终端环境下禁用 git 的交互提示，免得它去读终端、报出「No such device or address」
        // 这种无法定位的错误；用 putenv 注到本进程（子进程继承），不依赖 getenv() 是否可用。
        if (function_exists('putenv')) {
            putenv('GIT_TERMINAL_PROMPT=0');
            putenv('GCM_INTERACTIVE=never');
        }

        $process = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new ApiException(
                '无法执行 git，请确认已安装并加入 PATH（当前配置：' . $bin . '）',
                500
            );
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** git 是否可用 */
    public static function available(): bool
    {
        try {
            return self::run(['--version'])[0] === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** 当前 git 版本号（取不到返回空串） */
    public static function version(): string
    {
        [$code, $out] = self::run(['--version']);
        if ($code !== 0 || preg_match('~(\d+(?:\.\d+)+)~', $out, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    /**
     * git 版本过旧导致「不认某个选项」时的补充提示。
     *
     * 这类报错的原文是 `Unknown option: xxx` 加一大段 usage，使用者看不出根因是「git 太老」
     * （例如 1.8.5 之前的 git 不支持 `-C <dir>`，1.7.x 也不认 GIT_TERMINAL_PROMPT）。
     */
    public static function legacyHint(string $err): string
    {
        if (stripos($err, 'unknown option') === false && stripos($err, 'usage: git') === false) {
            return '';
        }

        $version = self::version();

        return '（当前 git' . ($version !== '' ? ' ' . $version : '版本偏旧') . ' 不支持该命令所需的选项，'
            . '请升级 git 到 1.8.5 以上（建议 2.x）后重试）';
    }

    /**
     * 目标版本在缓存仓库里的「新鲜」引用。
     *
     * `fetch` 只更新远端跟踪分支（`origin/*`）与 tag，**不会**动本地分支；
     * 而缓存仓库是 clone 出来的，本地分支永远停在 clone 那一刻。
     * 因此分支名一律改走 `origin/<ref>`，否则 `reset --hard main` 会一直得到旧 commit。
     *
     * tag 优先于同名分支（与 git 自身的 ref 解析顺序一致），
     * commit sha / `origin/xxx` 等解析不到本地分支的引用原样返回。
     */
    public static function freshRef(string $repo, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return $ref;
        }

        if (self::resolveCommit($repo, 'refs/tags/' . $ref) !== '') {
            return $ref;
        }

        if (self::resolveCommit($repo, 'refs/remotes/origin/' . $ref) !== '') {
            return 'origin/' . $ref;
        }

        return $ref;
    }

    /**
     * 切到目标版本（无条件重写工作区），返回该版本的 commit。
     *
     * 用 `reset --hard` 而不是 `checkout`：checkout 在「已处于该 ref 且工作区干净」时会跳过写文件，
     * 于是行尾转换配置变化后旧工作区不会被修正；reset --hard 每次都按当前配置重写。
     */
    public static function checkout(string $repo, string $ref): string
    {
        $ref = self::freshRef($repo, $ref);

        [$code, , $err] = self::run(['reset', '--hard', '--quiet', $ref], $repo);

        if ($code !== 0) {
            // 目标可能是刚发布的 tag：补一次 fetch 再试
            self::run(['fetch', '--quiet', '--tags', '--force', 'origin'], $repo);
            [$code, , $err] = self::run(['reset', '--hard', '--quiet', $ref], $repo);
        }

        if ($code !== 0) {
            throw new ApiException(
                "切换上游版本失败（{$ref}）：" . Upgrader::maskCredentials(trim($err)) . self::legacyHint($err),
                500
            );
        }

        [$ok, $out] = self::run(['rev-parse', 'HEAD'], $repo);

        return $ok === 0 ? trim($out) : '';
    }

    /** 解析某个 ref 的 commit（不存在返回空串，不抛异常） */
    public static function resolveCommit(string $repo, string $ref): string
    {
        if ($ref === '') {
            return '';
        }

        [$code, $out] = self::run(['rev-parse', '--verify', '--quiet', $ref], $repo);

        return $code === 0 ? trim($out) : '';
    }

    /**
     * 上游提交记录（`from..to`）。
     *
     * @return array<int,array{hash:string,short:string,author:string,date:string,message:string}>
     */
    public static function commits(string $repo, string $from, string $to, int $limit = 100): array
    {
        if ($from === '' || $to === '' || $from === $to) {
            return [];
        }

        [$code, $out] = self::run([
            'log', '--no-merges',
            '--date=format:%Y-%m-%d %H:%M',
            '--pretty=format:%H%x1f%h%x1f%an%x1f%ad%x1f%s',
            '-n', (string)$limit,
            $from . '..' . $to,
        ], $repo);
        if ($code !== 0) {
            return [];
        }

        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\x1f", $line);
            if (count($parts) < 5) {
                continue;
            }
            $rows[] = [
                'hash'    => $parts[0],
                'short'   => $parts[1],
                'author'  => $parts[2],
                'date'    => $parts[3],
                'message' => $parts[4],
            ];
        }

        return $rows;
    }

    /**
     * 文件行数统计（`from..to`）。
     *
     * 用 `-z` 分隔，避免文件名含制表符 / 空格时解析出错。
     *
     * @return array<string,array{added:int,deleted:int,binary:bool}>
     */
    public static function diffStat(string $repo, string $from, string $to): array
    {
        if ($from === '' || $to === '' || $from === $to) {
            return [];
        }

        [$code, $out] = self::run(['diff', '--numstat', '--no-renames', '-z', $from, $to], $repo);
        if ($code !== 0) {
            return [];
        }

        $stats = [];
        foreach (explode("\0", $out) as $entry) {
            $entry = trim($entry, "\n");
            if ($entry === '') {
                continue;
            }
            $parts = explode("\t", $entry);
            if (count($parts) < 3) {
                continue;
            }

            $binary           = $parts[0] === '-';
            $stats[$parts[2]] = [
                'added'   => $binary ? 0 : (int)$parts[0],
                'deleted' => $binary ? 0 : (int)$parts[1],
                'binary'  => $binary,
            ];
        }

        return $stats;
    }
}
