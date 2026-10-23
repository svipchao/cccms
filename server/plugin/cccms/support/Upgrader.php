<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use FilesystemIterator;
use plugin\cccms\support\Upgrade\GitClient;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * 上游代码同步器：用「基线 / 本地 / 上游」三份哈希判断哪些文件能安全覆盖。
 *
 * | 分类 | 条件 | 默认处置 |
 * |---|---|---|
 * | `new` | 上游有、本地无、基线也没有 | 直接写入 |
 * | `safe` | 本地 == 基线、上游 != 基线 | 覆盖 |
 * | `local` | 本地 != 基线、上游 == 基线 | 保留（本地定制） |
 * | `deleted` | 上游有、本地无、基线里有 | 保留（本地主动删除） |
 * | `conflict` | 两边都改了 | 只报告，强制覆盖需先备份 |
 * | `removed` | 上游删除、本地 == 基线 | 只报告，清理才删除 |
 * | `same` | 三方一致 | 跳过 |
 *
 * 本地独有文件永远不动，业务插件天然安全。上游仓库缓存在 runtime 下，不依赖
 * 本地 git；覆盖 / 删除前一律备份；哈希前统一行尾，避免 CRLF 被误判为「改过」。
 */
final class Upgrader
{
    /** 上游新增，本地没有 */
    public const NEW = 'new';

    /** 本地没改过、上游改了 → 可安全覆盖 */
    public const SAFE = 'safe';

    /** 本地改了、上游没动 → 保留 */
    public const LOCAL = 'local';

    /** 两边都改了 → 需人工决定 */
    public const CONFLICT = 'conflict';

    /** 上游删除了该文件（本地未改） */
    public const REMOVED = 'removed';

    /** 本地删除了该文件，上游仍有 → 尊重本地（多为重命名 / 精简） */
    public const DELETED = 'deleted';

    /** 三方一致，无需处理 */
    public const SAME = 'same';

    /** 分类标签（输出用） */
    public const LABELS = [
        self::NEW      => '上游新增',
        self::SAFE     => '可安全覆盖',
        self::LOCAL    => '本地已改(保留)',
        self::DELETED  => '本地已删除(保留)',
        self::CONFLICT => '冲突(需合并)',
        self::REMOVED  => '上游已移除',
        self::SAME     => '已是最新',
    ];

    /** 界面展示顺序：越靠前越需要关注 */
    public const ORDER = [
        self::CONFLICT, self::SAFE, self::NEW, self::REMOVED, self::LOCAL, self::DELETED, self::SAME,
    ];

    // ---------------------------------------------------------------------
    // 配置与路径
    // ---------------------------------------------------------------------

    /**
     * 结构性排除项：**不依赖配置文件**，任何情况下都生效。
     *
     * 覆盖它们轻则站点起不来，重则把线上密钥换成上游示例值：
     * 密钥（`.env`）、依赖（`vendor` / `node_modules`）、运行数据（`runtime`）、构建产物（`dist`）。
     * 配置里的 `exclude` 只能在此基础上**追加**，不能移除 —— 否则配置漏读一次就等于全线放开。
     */
    private const SAFETY_EXCLUDE = [
        '.git',
        'server/.env',
        'server/vendor',
        'server/runtime',
        'frontend/.env',
        'frontend/.env.local',
        'frontend/.env.production',
        'frontend/node_modules',
        'frontend/dist',
    ];

    /** 配置文件兜底读取结果（CLI / 测试进程里没有 webman 配置仓库，避免每次调用都 require） */
    private static ?array $fileConfig = null;

    /**
     * 读取本模块配置（缺失项用默认值兜底）。
     *
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        $defaults = [
            // 与 config/upgrade.php 保持一致：默认关闭，只有本地开发环境才打开
            'enable'         => false,
            'remotes'        => [],
            'default_source' => '',
            'track'          => 'main',
            'base'           => 'v0.0.1',
            'include'        => [],
            'exclude'        => [],
            'cache_dir'      => 'runtime/cccms-upgrade/repo',
            'state_file'     => 'runtime/cccms-upgrade/state.json',
            'backup_dir'     => 'runtime/cccms-upgrade/backups',
            'git'            => 'git',
        ];

        try {
            $conf = config('plugin.cccms.upgrade');
        } catch (Throwable) {
            $conf = null;
        }

        // CLI / 测试运行器不加载 webman 的插件配置，此时 `config()` 拿到 null。
        // 直接读配置文件兜底（与 Terminal / Realtime 同一口径）：配置读不到就退化成
        // 「只有 SAFETY_EXCLUDE 生效」，绝不能因为读不到配置而把排除清单清空。
        if (!is_array($conf) || $conf === []) {
            $conf = self::fileConfig();
        }

        $settings            = array_merge($defaults, $conf);
        $settings['exclude'] = array_merge(self::SAFETY_EXCLUDE, (array)($settings['exclude'] ?? []));

        return $settings;
    }

    /**
     * 直接读 plugin/cccms/config/upgrade.php（不经 webman 配置仓库）。
     *
     * @return array<string,mixed>
     */
    private static function fileConfig(): array
    {
        if (self::$fileConfig !== null) {
            return self::$fileConfig;
        }

        $file = dirname(__DIR__) . '/config/upgrade.php';
        $conf = is_file($file) ? require $file : [];

        return self::$fileConfig = is_array($conf) ? $conf : [];
    }

    /**
     * 可用同步源。
     *
     * @return array<int,array{key:string,label:string,url:string}>
     */
    public static function sources(): array
    {
        $sources = [];

        foreach ((array)self::settings()['remotes'] as $key => $conf) {
            // 允许简写：'gitee' => 'https://...'
            if (is_string($conf)) {
                $conf = ['url' => $conf];
            }
            if (!is_array($conf)) {
                continue;
            }

            $sources[] = [
                'key'   => (string)$key,
                'label' => (string)($conf['label'] ?? $key),
                'url'   => (string)($conf['url'] ?? ''),
            ];
        }

        return array_values(array_filter($sources, static fn (array $s): bool => $s['url'] !== ''));
    }

    /** 默认源 key（配置无效时退化为第一个可用源） */
    public static function defaultSource(): string
    {
        $configured = (string)self::settings()['default_source'];
        $keys       = array_column(self::sources(), 'key');

        if ($configured !== '' && in_array($configured, $keys, true)) {
            return $configured;
        }

        return (string)($keys[0] ?? '');
    }

    /**
     * 解析源：不传用默认源；key 非法则报错。
     *
     * @return array{key:string,label:string,url:string}
     */
    public static function resolveSource(?string $source = null): array
    {
        $all = self::sources();
        if ($all === []) {
            throw new ApiException('未配置任何上游仓库地址（plugin.cccms.upgrade.remotes）', 500);
        }

        $key = $source !== null && $source !== '' ? $source : self::defaultSource();

        foreach ($all as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $keys = implode(' / ', array_column($all, 'key'));

        throw new ApiException("未知的同步源「{$key}」，可用：{$keys}", 422);
    }

    /** 项目根（server 的上一级，即仓库根）；与危险功能门禁共用同一路径判定 */
    public static function root(): string
    {
        return Environment::root();
    }

    /** 项目根内的相对路径 → 绝对路径 */
    public static function path(string $relative): string
    {
        return self::root() . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /** 绝对路径 → 项目根内的相对路径 */
    public static function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);
        $root     = self::root() . '/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }

    /** 配置里的目录项（相对 server 根）→ 绝对路径；已是绝对路径则原样返回 */
    private static function resolve(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#^([A-Za-z]:/|/)#', $path) === 1) {
            return $path;
        }

        return rtrim(str_replace('\\', '/', base_path()), '/') . '/' . ltrim($path, '/');
    }

    /** 某个源的缓存仓库目录（每个源独立一份，互不干扰） */
    private static function repoDir(string $source): string
    {
        return self::resolve((string)self::settings()['cache_dir']) . '/' . $source;
    }

    private static function stateFile(): string
    {
        return self::resolve((string)self::settings()['state_file']);
    }

    private static function backupDir(): string
    {
        return self::resolve((string)self::settings()['backup_dir']);
    }

    // ---------------------------------------------------------------------
    // 清单过滤
    // ---------------------------------------------------------------------

    /**
     * 是否被排除（仅供目录剪枝与文件判断复用）。
     *
     * 两条独立规则：密钥文件按**文件名**判定（放哪一层都必须排除，新增插件自带的
     * `.env` 同样受保护），其余按配置里的路径前缀判定（必须按目录边界匹配，
     * 避免 `server/vendor` 误伤 `server/vendor_patch.php`）。
     */
    public static function excluded(string $relative): bool
    {
        $path = trim(str_replace('\\', '/', $relative), '/');
        if ($path === '') {
            return false;
        }

        // `.env` / `.env.local` / `.env.production` 永不同步；`.env.example` 是模板（无真实值），允许同步
        $base = basename($path);
        if ($base === '.env' || str_starts_with($base, '.env.')) {
            return $base !== '.env.example';
        }

        foreach ((array)self::settings()['exclude'] as $prefix) {
            $prefix = trim(str_replace('\\', '/', (string)$prefix), '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }

        return false;
    }

    /** 是否属于同步范围（未被排除，且命中 include 白名单——白名单为空即全部） */
    public static function accepts(string $relative): bool
    {
        $path = trim(str_replace('\\', '/', $relative), '/');
        if ($path === '' || self::excluded($path)) {
            return false;
        }

        $include = array_filter(array_map(
            static fn ($v): string => trim(str_replace('\\', '/', (string)$v), '/'),
            (array)self::settings()['include']
        ));
        if ($include === []) {
            return true;
        }

        foreach ($include as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // 哈希
    // ---------------------------------------------------------------------

    /**
     * 内容摘要。
     *
     * 文本文件先归一化行尾，避免 CRLF / LF 差异被误判成「改过」；
     * 二进制（含 NUL 字节）原样哈希 —— git 本来也不会对它们做行尾转换。
     */
    public static function digest(string $content): string
    {
        if (str_contains($content, "\0")) {
            return sha1($content);
        }

        return sha1(str_replace("\r\n", "\n", $content));
    }

    /** 文件摘要；文件不存在返回 null */
    public static function digestFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : self::digest($content);
    }

    // ---------------------------------------------------------------------
    // git
    // ---------------------------------------------------------------------

    /** git 是否可用（命令执行在 `Upgrade\GitClient`，这里只保留对外入口） */
    public static function gitAvailable(): bool
    {
        return GitClient::available();
    }

    /**
     * 确保缓存里有一份可用的上游仓库，返回仓库目录。
     *
     * 首次 clone，之后按需 fetch；`$fetch = false` 表示只用已有缓存（离线模式）。
     * 缓存损坏（半删、缺 origin 配置）时自动重建一次，避免卡在无法诊断的 git 报错上。
     */
    private static function ensureRepo(string $source, bool $fetch = true): string
    {
        $repo = self::repoDir($source);
        $url  = self::resolveSource($source)['url'];

        if (!is_dir($repo . '/.git')) {
            self::createRepo($repo, $url, $source);

            return $repo;
        }

        if ($fetch) {
            [$code, , $err] = GitClient::run(['fetch', '--quiet', '--tags', '--force', 'origin'], $repo);
            if ($code !== 0) {
                // 目录还在但结构已坏（例如手动删了一半）→ 重建后再用
                self::createRepo($repo, $url, $source);
            }
        }

        return $repo;
    }

    /** 清空并重新 clone 缓存仓库 */
    private static function createRepo(string $repo, string $remote, string $source): void
    {
        self::rmdir($repo);
        if (is_dir($repo . '/.git')) {
            throw new ApiException('缓存目录无法清理，请手动删除后重试：' . $repo, 500);
        }

        @mkdir(dirname($repo), 0755, true);

        [$code, , $err] = GitClient::run(['clone', '--quiet', $remote, $repo]);
        if ($code !== 0) {
            throw new ApiException(
                '拉取上游仓库失败：' . self::maskCredentials(trim($err ?: 'clone 退出码 ' . $code))
                    . self::authHint($err, $source) . self::selfCheck($remote),
                500
            );
        }
    }

    /**
     * 去掉 URL 里的凭据：`https://user:token@host/...` → `https://***@host/...`。
     *
     * 接口响应 / 异常信息里回显 URL 前都要过这一层，避免令牌泄露（不误伤 `git@host:path`）。
     */
    public static function maskCredentials(string $text): string
    {
        return self::replaceCredentials($text, '***');
    }

    /** 去掉 URL 里的凭据（探测「匿名可达性」时用） */
    private static function stripCredentials(string $url): string
    {
        return self::replaceCredentials($url, '');
    }

    /** 替换 URL 的 userinfo 段（`://` 之后、`@` 之前），不会误伤 `git@host:path` 这类写法 */
    private static function replaceCredentials(string $text, string $replacement): string
    {
        return (string)preg_replace('~(?<=://)[^/@\s]+(?=@)~', $replacement, $text);
    }

    /** 认证类失败的补充提示：说明该源怎么配凭据（私有仓库/需登录的源） */
    private static function authHint(string $err, string $source = ''): string
    {
        $needle = '/could not read Username|terminal prompts disabled|Authentication failed|'
            . 'Permission denied|Invalid username|HTTP 401/i';
        if (preg_match($needle, $err) !== 1) {
            return '';
        }

        $name = $source !== '' ? '「' . $source . '」' : '';
        // 内置源各自对应一个 .env 键（自定义源由使用者在 config 里写死 URL，不给具体键名）
        $envKey = in_array($source, ['gitee', 'github'], true) ? 'CCCMS_UPGRADE_URL_' . strtoupper($source) : '';

        return '（该同步源' . $name . '要求登录：在 server/.env 里给 '
            . ($envKey !== '' ? $envKey : '该源') . ' 配置带令牌的 URL'
            . '（形如 https://<账号>:<令牌>@gitee.com/<owner>/<repo>.git），'
            . '或改用 SSH 部署公钥，或在页面上切换其它同步源）';
    }

    /** 失败自检：把「本机到同步源的真实状况」写进报错（只在失败路径调用，正常同步不走） */
    private static function selfCheck(string $url): string
    {
        $parts = [];

        $status = self::probeStatus($url);
        if ($status !== '') {
            $parts[] = $status;
        }

        $version = GitClient::version();
        if ($version !== '') {
            $parts[] = version_compare($version, '2.3.0', '<')
                ? 'git ' . $version . '（早于 2.3，不支持禁用交互提示）'
                : 'git ' . $version;
        }

        return $parts === [] ? '' : '；自检：' . implode('，', $parts);
    }

    /**
     * 探一次同步源的 HTTP 状态（只读）：匿名 / 带凭据各一次。
     *
     * 用来区分「地址需要认证」「凭据被拒」「本机 IP 被挡」「网络不通」四类问题。
     */
    private static function probeStatus(string $url): string
    {
        if (preg_match('~^https?://~i', $url) !== 1) {
            return '';
        }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return '';
        }

        // 探两次：不带凭据 / 带凭据。两者合起来才能区分问题类型 ——
        //   匿名 401 + 带凭据 200 → 正常；匿名 401 + 带凭据 403 → 凭据被拒（令牌 / 账号 / 权限）；
        //   两者都 403 → 本机 IP 被挡；都拿不到 → 网络 / 代理问题。
        $plain  = self::endpointStatus(self::stripCredentials($url));
        $authed = self::endpointStatus($url);

        if ($plain === '' || $authed === '') {
            return '访问该地址无响应（网络 / 代理问题？）';
        }

        if ($plain === $authed) {
            return '访问该地址返回 HTTP ' . $plain
                . (self::stripCredentials($url) !== $url ? '（匿名与带凭据结果一致，凭据未被接受）' : '');
        }

        $text = '访问该地址匿名 HTTP ' . $plain . '、带凭据 HTTP ' . $authed;

        // 这组组合的含义很明确：登录成功了，但**这个仓库不给该账号访问**
        if ($plain === '401' && $authed === '403') {
            $text .= '（已登录但无该仓库权限：仓库可能是私有、账号未完成实名认证，或该账号不是仓库成员）';
        }

        return $text;
    }

    /** 请求同步源的 git 端点，返回状态码（拿不到返回空串） */
    private static function endpointStatus(string $url): string
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true],
        ]);

        @file_get_contents(rtrim($url, '/') . '/info/refs?service=git-upload-pack', false, $context);

        // 响应头由 file_get_contents 写在调用作用域的同名变量里；取最后一次（跟随重定向后的）
        $status = '';
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $line, $match) === 1) {
                $status = $match[1];
            }
        }

        return $status;
    }

    /**
     * 上游提交记录（`from..to`），实现见 `Upgrade\GitClient::commits()`。
     *
     * @return array<int,array{hash:string,short:string,author:string,date:string,message:string}>
     */
    public static function commits(string $repo, string $from, string $to, int $limit = 100): array
    {
        return GitClient::commits($repo, $from, $to, $limit);
    }

    /**
     * 文件行数统计（`from..to`），实现见 `Upgrade\GitClient::diffStat()`。
     *
     * @return array<string,array{added:int,deleted:int,binary:bool}>
     */
    public static function diffStat(string $repo, string $from, string $to): array
    {
        return GitClient::diffStat($repo, $from, $to);
    }

    /**
     * 上游当前工作区（= 目标版本）内的文件清单。
     *
     * 以 `git ls-tree` 为准（只含被跟踪的文件），再套用 include / exclude 过滤。
     *
     * @return array<string,string> 相对路径 => sha1
     */
    private static function upstreamFiles(string $repo): array
    {
        [$code, $out, $err] = GitClient::run(['ls-tree', '-r', '-z', '--name-only', 'HEAD'], $repo);
        if ($code !== 0) {
            throw new ApiException(
                '读取上游文件清单失败：' . self::maskCredentials(trim($err)) . GitClient::legacyHint($err),
                500
            );
        }

        $files = [];
        foreach (explode("\0", $out) as $relative) {
            $relative = trim($relative);
            if ($relative === '' || !self::accepts($relative)) {
                continue;
            }
            $digest = self::digestFile($repo . '/' . $relative);
            if ($digest !== null) {
                $files[$relative] = $digest;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * 扫描本地文件（按 include / exclude 过滤，被排除的目录整体剪枝）。
     *
     * @return array<string,string> 相对路径 => sha1
     */
    public static function scanLocal(): array
    {
        $root = self::root();
        if (!is_dir($root)) {
            return [];
        }

        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter    = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $current): bool {
                $relative = self::relative($current->getPathname());

                // 目录：只按排除规则剪枝（include 白名单不用于剪枝，否则根目录会被剪掉）
                if ($current->isDir()) {
                    return !self::excluded($relative);
                }

                return self::accepts($relative);
            }
        );

        $files = [];
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            /** @var SplFileInfo $file */
            $digest = self::digestFile($file->getPathname());
            if ($digest !== null) {
                $files[self::relative($file->getPathname())] = $digest;
            }
        }
        ksort($files);

        return $files;
    }

    // ---------------------------------------------------------------------
    // 基线状态
    // ---------------------------------------------------------------------

    /**
     * 读取基线状态。
     *
     * 基线是**内容哈希**，与源无关：换源不需要重建基线。
     *
     * @return array<string,mixed> 空数组代表尚未初始化
     */
    public static function state(): array
    {
        $file = self::stateFile();
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    private static function saveState(array $state): void
    {
        $file = self::stateFile();
        @mkdir(dirname($file), 0755, true);
        file_put_contents(
            $file,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function initialized(): bool
    {
        return self::state() !== [];
    }

    /** 当前版本信息（界面顶部展示） */
    public static function current(): array
    {
        $state = self::state();

        return [
            'initialized' => $state !== [],
            'base'        => (string)($state['base'] ?? ''),
            'commit'      => (string)($state['base_commit'] ?? ''),
            'synced_at'   => (string)($state['synced_at'] ?? ''),
            'files'       => count((array)($state['files'] ?? [])),
        ];
    }

    // ---------------------------------------------------------------------
    // 对外动作
    // ---------------------------------------------------------------------

    /**
     * 当前运行环境与是否允许在线升级（页面用它渲染提醒，接口/命令用它拦截）。
     *
     * 两道门槛都过才允许：目录自检确认是本地开发环境 **且** 总开关开启。
     * `blocked_by` 说明是哪一道没过 —— 页面对两者的提示完全不同（一个是"怎么打开"，
     * 一个是"线上禁止使用"），不能混着说。
     *
     * @return array{key:string,allowed:bool,blocked_by:string,reason:string,marker:string}
     */
    public static function environment(): array
    {
        $blockedBy = Environment::blockedBy('upgrade');

        // 非开发环境是硬拦截，与总开关无关，优先报
        if ($blockedBy === 'environment') {
            return [
                'key'        => 'production',
                'allowed'    => false,
                'blocked_by' => 'environment',
                'reason'     => '当前不是本地开发环境（未发现 ' . Environment::DEV_MARKER . '），已禁止在线升级',
                'marker'     => Environment::DEV_MARKER,
            ];
        }

        if ($blockedBy === 'switch') {
            return [
                'key'        => 'dev',
                'allowed'    => false,
                'blocked_by' => 'switch',
                'reason'     => '上游同步已关闭（plugin.cccms.upgrade.enable = false）',
                'marker'     => Environment::DEV_MARKER,
            ];
        }

        return [
            'key'        => 'dev',
            'allowed'    => true,
            'blocked_by' => '',
            'reason'     => '',
            'marker'     => Environment::DEV_MARKER,
        ];
    }

    /** 对外动作的统一守卫：总开关关闭、或不在本地开发环境时一律拒绝 */
    private static function assertEnabled(): void
    {
        $env = self::environment();
        if (!$env['allowed']) {
            throw new ApiException($env['reason'], 403);
        }
    }

    /**
     * 建立基线：记录「当前代码基于的上游版本」。
     *
     * 首次使用必须走这一步，同时回答「我相对上游改了哪些文件」。
     *
     * @return array<string,mixed>
     */
    public static function init(?string $ref = null, bool $fetch = true, ?string $source = null): array
    {
        self::assertEnabled();

        $setting = self::settings();
        $src     = self::resolveSource($source);
        $ref     = $ref ?: (string)$setting['base'];
        $repo    = self::ensureRepo($src['key'], $fetch);

        // 上游还没打该 tag 时回退到跟踪分支：否则首次使用会因为「版本不存在」直接卡住
        $fallback = '';
        try {
            $commit = GitClient::checkout($repo, $ref);
        } catch (ApiException $e) {
            $track = (string)$setting['track'];
            if ($ref === $track) {
                throw $e;
            }
            $commit   = GitClient::checkout($repo, $track);
            $fallback = $ref;
            $ref      = $track;
        }

        $files = self::upstreamFiles($repo);

        $modified = [];
        $missing  = [];
        foreach ($files as $path => $digest) {
            $local = self::digestFile(self::path($path));
            if ($local === null) {
                $missing[] = $path;
            } elseif ($local !== $digest) {
                $modified[] = $path;
            }
        }

        $localOnly = [];
        foreach (self::scanLocal() as $path => $digest) {
            if (!isset($files[$path])) {
                $localOnly[] = $path;
            }
        }

        self::saveState([
            'base'        => $ref,
            'base_commit' => $commit,
            'source'      => $src['key'],
            'synced_at'   => date('Y-m-d H:i:s'),
            'files'       => $files,
        ]);

        return [
            'source'    => $src,
            'ref'       => $ref,
            'commit'    => $commit,
            'fallback'  => $fallback,
            'total'     => count($files),
            'modified'  => $modified,
            'missing'   => $missing,
            'localOnly' => $localOnly,
        ];
    }

    /**
     * 生成同步计划（只读，不写任何文件）。
     *
     * @param  string|null $ref    目标版本（分支 / tag / commit），null 用配置的 track
     * @param  bool        $fetch  是否联网刷新上游仓库
     * @param  string|null $source 同步源 key，null 用默认源
     * @return array<string,mixed>
     */
    public static function plan(?string $ref = null, bool $fetch = true, ?string $source = null): array
    {
        self::assertEnabled();

        $state = self::state();
        if ($state === []) {
            throw new ApiException('尚未建立基线，请先初始化（php webman cccms:update --init）', 500);
        }

        $src     = self::resolveSource($source);
        $ref     = $ref ?: (string)self::settings()['track'];
        $repo    = self::ensureRepo($src['key'], $fetch);
        $commit  = GitClient::checkout($repo, $ref);
        $remote  = self::upstreamFiles($repo);
        $base    = (array)($state['files'] ?? []);
        $baseRef = (string)($state['base'] ?? '');

        $items = [];
        foreach ($remote as $path => $digest) {
            $local = self::digestFile(self::path($path));

            if ($local === null) {
                // 基线里也没有 → 上游新增；基线里有 → 是本地主动删除，不能写回（否则重命名会被复活）
                $items[$path] = isset($base[$path]) ? self::DELETED : self::NEW;
            } elseif ($local === $digest) {
                $items[$path] = self::SAME;
            } elseif (!isset($base[$path])) {
                // 上游新增的路径，本地恰好已有同名文件（多半是下游自建）→ 不能覆盖
                $items[$path] = self::CONFLICT;
            } elseif ($local === $base[$path]) {
                $items[$path] = self::SAFE;
            } elseif ($digest === $base[$path]) {
                $items[$path] = self::LOCAL;
            } else {
                $items[$path] = self::CONFLICT;
            }
        }

        // 基线里有、上游新版没有 → 上游移除了该文件
        foreach ($base as $path => $digest) {
            if (isset($remote[$path])) {
                continue;
            }
            $local = self::digestFile(self::path($path));
            if ($local === null) {
                continue; // 本地也删了，无需处理
            }
            $items[$path] = $local === $digest ? self::REMOVED : self::LOCAL;
        }

        // 上游从基线到目标的变化：提交记录 + 每文件行数
        // 基线优先用状态里记下的 commit（精确），退化时才用符号 ref。
        // 统一以解析后的 commit 作区间端点：基线若记的是分支名（如 main），
        // 直接用 ref 名会被 git 解析成本地陈旧分支，比对结果随之失真。
        $baseCommit = (string)($state['base_commit'] ?? '');
        $from       = GitClient::resolveCommit($repo, $baseCommit) !== '' ? $baseCommit : GitClient::freshRef($repo, $baseRef);
        $stats      = self::diffStat($repo, $from, $commit);
        $commits    = self::commits($repo, $from, $commit);

        $files  = [];
        $added  = 0;
        $deleted = 0;
        foreach (self::ORDER as $kind) {
            foreach ($items as $path => $itemKind) {
                if ($itemKind !== $kind || $kind === self::SAME) {
                    continue;
                }
                $stat = $stats[$path] ?? ['added' => 0, 'deleted' => 0, 'binary' => false];
                $added   += $stat['added'];
                $deleted += $stat['deleted'];

                $files[] = [
                    'path'       => $path,
                    'kind'       => $kind,
                    'kind_label' => self::LABELS[$kind],
                    'added'      => $stat['added'],
                    'deleted'    => $stat['deleted'],
                    'binary'     => (bool)$stat['binary'],
                ];
            }
        }

        return [
            'source'      => $src,
            'source_key'  => $src['key'],
            'repo'        => $repo,
            'ref'         => $ref,
            'commit'      => $commit,
            'base'        => $baseRef,
            'base_commit' => GitClient::resolveCommit($repo, $from),
            'items'       => $items,
            'files'       => $files,
            'summary'     => self::summarize($items),
            'commits'     => $commits,
            'lines'       => ['added' => $added, 'deleted' => $deleted],
            'localOnly'   => self::localOnly($remote, $base),
            'modified'    => array_keys(array_filter(
                $items,
                static fn (string $kind): bool => $kind === self::LOCAL
            )),
        ];
    }

    /**
     * 执行计划：写入 / 覆盖 / 删除，并更新基线。
     *
     * `$force` 覆盖「两边都改过」的冲突文件；`$prune` 删除「上游已移除且本地未改」的文件；
     * `$forceAll` 是**最强档**：在 `$force` + `$prune` 之上，连「纯本地定制」(`local`) 也覆盖、
     * 「本地删掉的」(`deleted`) 也恢复 —— 适合「下游落后太多，直接整体对齐上游」的场景。
     * 所有覆盖 / 删除都会先按原相对路径备份。
     *
     * @param  array<string,mixed> $plan  plan() 的返回值
     * @return array{written:int,removed:int,backed:int,skipped:array<int,string>,backupDir:string,report:string}
     */
    public static function apply(array $plan, bool $force = false, bool $prune = false, bool $forceAll = false): array
    {
        $repo   = (string)($plan['repo'] ?? '');
        if ($repo === '' || !is_dir($repo)) {
            throw new ApiException('同步计划已失效，请重新检查更新', 500);
        }

        $backup = self::backupDir() . '/' . date('YmdHis');
        $items  = (array)($plan['items'] ?? []);

        $written = 0;
        $removed = 0;
        $backed  = 0;
        $skipped = [];

        foreach ($items as $path => $kind) {
            $absolute = self::path($path);

            $overwrite = $kind === self::SAFE
                || $kind === self::NEW
                || ($kind === self::CONFLICT && ($force || $forceAll))
                || ($kind === self::LOCAL && $forceAll)
                || ($kind === self::DELETED && $forceAll);

            if ($overwrite) {
                $backed += self::backup($absolute, $backup, $path) ? 1 : 0;
                self::copy($repo . '/' . $path, $absolute);
                $written++;
                continue;
            }

            if ($kind === self::REMOVED) {
                if (!$prune && !$forceAll) {
                    $skipped[] = $path;
                    continue;
                }
                $backed += self::backup($absolute, $backup, $path) ? 1 : 0;
                @unlink($absolute);
                $removed++;
            }
        }

        $report = self::writeReport($backup, $plan, $written, $removed, $backed, $skipped);

        // 基线推进到目标版本：已覆盖的文件下次即视为「未改动」，
        // 未覆盖的冲突文件仍保留本地内容，下次依旧会被识别成「本地已改」
        self::saveState([
            'base'        => (string)($plan['ref'] ?? ''),
            'base_commit' => (string)($plan['commit'] ?? ''),
            'source'      => (string)($plan['source_key'] ?? ''),
            'synced_at'   => date('Y-m-d H:i:s'),
            'files'       => self::upstreamFiles($repo),
        ]);

        return [
            'written'   => $written,
            'removed'   => $removed,
            'backed'    => $backed,
            'skipped'   => $skipped,
            'backupDir' => $backed > 0 ? $backup : '',
            'report'    => $report,
        ];
    }

    /**
     * 监控 / 页面用的只读状态：任何异常都转成「不可用」而不是抛出。
     *
     * @return array<string,mixed>
     */
    public static function status(?string $source = null, ?string $ref = null): array
    {
        $result = [
            'enabled'     => (bool)self::settings()['enable'],
            'environment' => self::environment(),
            'initialized' => false,
            'ok'          => false,
            'message'     => '',
            'ref'         => '',
            'base'        => '',
            'summary'     => [],
            'conflicts'   => [],
        ];

        if (!$result['enabled']) {
            $result['message'] = '上游同步已关闭';

            return $result;
        }

        try {
            if (!self::gitAvailable()) {
                $result['message'] = '未检测到 git 可执行文件';

                return $result;
            }

            if (!self::initialized()) {
                $result['message'] = '尚未建立基线';

                return $result;
            }

            $plan = self::plan($ref, true, $source);
            $result['initialized'] = true;
            $result['ok']          = true;
            $result['ref']         = (string)$plan['ref'];
            $result['base']        = (string)$plan['base'];
            $result['summary']     = (array)$plan['summary'];
            $result['conflicts']   = array_keys(array_filter(
                (array)$plan['items'],
                static fn (string $kind): bool => $kind === self::CONFLICT
            ));
            $result['message']     = self::summarizeText($plan);

            return $result;
        } catch (Throwable $e) {
            $result['message'] = '检查失败：' . $e->getMessage();

            return $result;
        }
    }

    /**
     * 远端可用版本（tag）列表。
     *
     * @return array<int,string>
     */
    public static function remoteTags(?string $source = null): array
    {
        self::assertEnabled();

        $url = self::resolveSource($source)['url'];

        [$code, $out, $err] = GitClient::run(['ls-remote', '--tags', '--refs', $url]);
        if ($code !== 0) {
            throw new ApiException(
                '读取远端版本失败：' . self::maskCredentials(trim($err))
                    . self::authHint($err, $source) . self::selfCheck($url),
                500
            );
        }

        $tags = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $ref   = $parts[1] ?? '';
            if (str_starts_with($ref, 'refs/tags/')) {
                $tags[] = substr($ref, strlen('refs/tags/'));
            }
        }

        // 版本号倒序（v0.0.10 应排在 v0.0.9 前面）
        usort($tags, static fn (string $a, string $b): int => version_compare(
            ltrim($b, 'vV'),
            ltrim($a, 'vV')
        ));

        return $tags;
    }

    /** 用文字概括一份计划（命令输出与监控摘要共用） */
    public static function summarizeText(array $plan): string
    {
        $summary = (array)($plan['summary'] ?? []);
        $parts   = [];

        foreach ([self::NEW, self::SAFE, self::CONFLICT, self::REMOVED, self::LOCAL, self::DELETED] as $kind) {
            $count = (int)($summary[$kind] ?? 0);
            if ($count > 0) {
                $parts[] = self::LABELS[$kind] . ' ' . $count;
            }
        }

        if ($parts === []) {
            return '已是最新（目标 ' . ($plan['ref'] ?? '') . '）';
        }

        return implode('，', $parts);
    }

    // ---------------------------------------------------------------------
    // 内部工具
    // ---------------------------------------------------------------------

    /**
     * 分类计数。
     *
     * @param  array<string,string> $items
     * @return array<string,int>
     */
    public static function summarize(array $items): array
    {
        $summary = array_fill_keys(array_keys(self::LABELS), 0);
        foreach ($items as $kind) {
            $summary[$kind] = ($summary[$kind] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * 本地独有文件（既不在上游也不在基线）。
     *
     * @return array<int,string>
     */
    private static function localOnly(array $remote, array $base): array
    {
        $paths = [];
        foreach (self::scanLocal() as $path => $digest) {
            if (!isset($remote[$path]) && !isset($base[$path])) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** 覆盖前备份（保持相对路径，便于整棵回滚） */
    private static function backup(string $absolute, string $backupDir, string $relative): bool
    {
        if (!is_file($absolute)) {
            return false;
        }

        $target = $backupDir . '/' . $relative;
        @mkdir(dirname($target), 0755, true);

        return @copy($absolute, $target);
    }

    private static function copy(string $from, string $to): void
    {
        if (!is_file($from)) {
            throw new ApiException("上游文件缺失，无法写入：{$from}", 500);
        }
        @mkdir(dirname($to), 0755, true);
        if (!@copy($from, $to)) {
            throw new ApiException("写入失败：{$to}", 500);
        }
    }

    /** 写一份人可读的报告到备份目录，方便回看与回滚 */
    private static function writeReport(
        string $backupDir,
        array $plan,
        int $written,
        int $removed,
        int $backed,
        array $skipped
    ): string {
        if ($written === 0 && $removed === 0) {
            return '';
        }

        @mkdir($backupDir, 0755, true);
        $file = $backupDir . '/report.txt';

        $lines = [
            'CCCMS 上游同步报告',
            '时间：' . date('Y-m-d H:i:s'),
            '同步源：' . (string)($plan['source']['label'] ?? $plan['source_key'] ?? ''),
            '目标版本：' . ($plan['ref'] ?? '') . ' @ ' . ($plan['commit'] ?? ''),
            '原基线：' . ($plan['base'] ?? ''),
            '写入 / 覆盖：' . $written . '，删除：' . $removed . '，备份：' . $backed,
            '',
            '文件清单：',
        ];

        foreach ((array)($plan['items'] ?? []) as $path => $kind) {
            if ($kind === self::SAME || $kind === self::LOCAL) {
                continue;
            }
            $mark = in_array($path, $skipped, true) ? '（跳过）' : '';
            $lines[] = sprintf('  [%s] %s %s', $kind, $path, $mark);
        }

        $lines[] = '';
        $lines[] = '回滚方式：把本目录下的文件按相对路径覆盖回项目根即可。';

        file_put_contents($file, implode(PHP_EOL, $lines));

        return $file;
    }

    /** 递归删除目录（清缓存用）。Windows 下 git 的对象文件是只读的，删除前先去掉只读位 */
    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
                continue;
            }

            @chmod($path, 0644);
            @unlink($path);
        }

        @rmdir($dir);
    }
}
