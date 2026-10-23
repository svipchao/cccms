<?php

declare(strict_types=1);

namespace plugin\cccms\support;

/**
 * 在线命令终端（F2）的白名单执行器。
 *
 * ## 三条硬约束
 *
 * 1. **不经过 shell**：`proc_open()` 传**数组** argv（不经 `sh -c`），参数值即使含空格 / 分号 /
 *    反引号也只会被当成一个普通参数。这是本类唯一依赖的注入防线，不靠转义函数。
 * 2. **参数只认白名单**：`validate()` 按 `config/terminal.php` 里声明的类型（int / bool / text）
 *    逐个校验，未声明的键一律丢弃；校验后的值才进入 argv。
 * 3. **执行在队列里**：本类只负责「怎么跑一条已登记的命令」，投递 / 互斥 / 输出留存由
 *    `TerminalLogic` + `JobQueue` 负责 —— 在线请求不该被一条几十秒的命令占住。
 */
final class Terminal
{
    /** 普通命令：只需 `cccms:terminal:run` */
    public const RISK_NORMAL = 1;

    /** 危险及以上：还需要 `cccms:terminal:danger`，且请求要带 key 作二次确认 */
    public const RISK_DANGER = 2;

    /** 超时终止时使用的约定退出码（与 coreutils 的 timeout 一致，便于人肉辨认） */
    public const EXIT_TIMEOUT = 124;

    /** 配置缓存：与 webman 的配置语义一致（进程启动时读取，改完需 `php webman reload`） */
    private static ?array $configCache = null;

    /**
     * 总配置。
     *
     * 优先走框架配置（`plugin.cccms.terminal`）；CLI / 未加载插件配置的进程（如测试运行器）
     * 直接读文件兜底 —— 否则命令表会在 CLI 里凭空为空，排查时极难理解。
     * 两边都拿不到时返回 `enable = false`：配置文件丢失不应该让终端自动变成「可用」。
     *
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        try {
            $config = config('plugin.cccms.terminal');
        } catch (\Throwable) {
            $config = null;
        }

        if (!is_array($config) || $config === []) {
            $file   = dirname(__DIR__) . '/config/terminal.php';
            $loaded = is_file($file) ? require $file : null;
            $config = is_array($loaded) ? $loaded : ['enable' => false];
        }

        return self::$configCache = $config;
    }

    public static function enabled(): bool
    {
        return (bool)(self::config()['enable'] ?? false);
    }

    public static function timeout(): int
    {
        return max(5, min(600, (int)(self::config()['timeout'] ?? 120)));
    }

    public static function outputLimit(): int
    {
        return max(1000, min(64000, (int)(self::config()['output_limit'] ?? 8000)));
    }

    /**
     * 命令目录（已翻译、已归一化）：给接口与前端用。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function commands(): array
    {
        $commands = self::config()['commands'] ?? [];
        if (!is_array($commands)) {
            return [];
        }

        $out = [];
        foreach ($commands as $command) {
            $normalized = self::normalize($command);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * 按 key 找命令（返回归一化后的结构；不存在返回 null）。
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        foreach (self::commands() as $command) {
            if ($command['key'] === $key) {
                return $command;
            }
        }

        return null;
    }

    /**
     * 校验并归一化参数。
     *
     * 只返回**声明过**的键；缺省的非必填参数直接不出现（命令行也就不带这个开关），
     * 而不是塞一个空串 —— `--from=` 与不带 `--from` 对 Symfony Console 是两回事。
     *
     * @param  array<string,mixed> $command 归一化后的命令
     * @param  array<string,mixed> $input   客户端提交的原始参数
     * @return array<string,int|bool|string>
     */
    public static function validate(array $command, array $input): array
    {
        $out = [];

        foreach ((array)($command['params'] ?? []) as $def) {
            $name     = (string)$def['name'];
            $type     = (string)$def['type'];
            $required = (bool)($def['required'] ?? false);
            $label    = I18n::t((string)$def['label']);
            $exists   = array_key_exists($name, $input);
            $raw      = $exists ? $input[$name] : null;

            if ($type === 'bool') {
                // 未提交的 flag：不出现即「不开启」，不是错误
                if (!$exists || $raw === null || $raw === '') {
                    if ($required) {
                        throw new ApiException(I18n::t('terminal.param_required', ['name' => $label]), 422);
                    }
                    continue;
                }
                $out[$name] = self::boolValue($raw, $label);
                continue;
            }

            $text = is_scalar($raw) ? trim((string)$raw) : '';
            if ($text === '') {
                if ($required) {
                    throw new ApiException(I18n::t('terminal.param_required', ['name' => $label]), 422);
                }
                continue;
            }

            // NUL 与换行没有任何合法用途，出现在参数里只可能是注入尝试
            if (str_contains($text, "\0") || str_contains($text, "\n") || str_contains($text, "\r")) {
                throw new ApiException(I18n::t('terminal.param_invalid', ['name' => $label]), 422);
            }

            if ($type === 'int') {
                if (preg_match('/^\d{1,10}$/', $text) !== 1) {
                    throw new ApiException(I18n::t('terminal.param_invalid', ['name' => $label]), 422);
                }
                $value = (int)$text;
                $min   = (int)($def['min'] ?? PHP_INT_MIN);
                $max   = (int)($def['max'] ?? PHP_INT_MAX);
                if ($value < $min || $value > $max) {
                    throw new ApiException(
                        I18n::t('terminal.param_range', ['name' => $label, 'min' => $min, 'max' => $max]),
                        422
                    );
                }
                $out[$name] = $value;
                continue;
            }

            // text：正则 + 长度上限都由配置给，缺省按「只允许常见安全字符」兜底
            $pattern = (string)($def['pattern'] ?? '#^[A-Za-z0-9_.\-/:@]+$#');
            if (preg_match($pattern, $text) !== 1) {
                throw new ApiException(I18n::t('terminal.param_invalid', ['name' => $label]), 422);
            }
            $maxLength = (int)($def['max_length'] ?? 120);
            if (mb_strlen($text) > $maxLength) {
                throw new ApiException(I18n::t('terminal.param_length', ['name' => $label, 'max' => $maxLength]), 422);
            }
            $out[$name] = $text;
        }

        return $out;
    }

    /**
     * 构建 argv（数组，不经 shell）。
     *
     * `{php}` → 当前 PHP 解释器，`{root}` → 项目根：配置里不写绝对路径，
     * 换部署目录 / 换 PHP 版本都不用改登记表。
     *
     * @param  array<string,mixed>      $command 归一化后的命令
     * @param  array<string,int|bool|string> $params  `validate()` 的结果
     * @return array<int,string>
     */
    public static function argv(array $command, array $params = []): array
    {
        $root = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        $argv = [];

        foreach ((array)($command['argv'] ?? []) as $part) {
            $argv[] = strtr((string)$part, ['{php}' => PHP_BINARY, '{root}' => $root]);
        }

        foreach ((array)($command['params'] ?? []) as $def) {
            $name = (string)$def['name'];
            if (!array_key_exists($name, $params)) {
                continue;
            }

            // CLI 习惯用连字符：dry_run → --dry-run
            $option = '--' . str_replace('_', '-', $name);
            if (($def['type'] ?? '') === 'bool') {
                if ($params[$name] === true) {
                    $argv[] = $option;
                }
                continue;
            }

            $argv[] = $option . '=' . (string)$params[$name];
        }

        return $argv;
    }

    /**
     * 执行一条命令（阻塞，仅允许在队列作业里调用）。
     *
     * 输出同时收 stdout 与 stderr：两者分开会让「为什么失败了」变成猜谜，
     * 而顺序本来就是交错的，合并进同一个缓冲更接近终端里看到的样子。
     *
     * @param  array<string,mixed>           $command
     * @param  array<string,int|bool|string> $params
     * @return array{exit_code:int,output:string,cost:int,timed_out:bool}
     */
    public static function execute(array $command, array $params = []): array
    {
        $argv    = self::argv($command, $params);
        $timeout = self::timeout();
        $limit   = self::outputLimit();
        $start   = microtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($argv, $descriptors, $pipes, self::workingDir(), null);
        if (!is_resource($process)) {
            throw new ApiException(I18n::t('terminal.spawn_failed'), 500);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output    = '';
        $truncated = false;
        $timedOut  = false;
        $exitCode  = 1;

        while (true) {
            $status = proc_get_status($process);

            foreach ([1, 2] as $index) {
                while (($chunk = fread($pipes[$index], 8192)) !== false && $chunk !== '') {
                    if (strlen($output) >= $limit) {
                        $truncated = true;
                        continue;
                    }
                    $output .= substr($chunk, 0, $limit - strlen($output));
                    if (strlen($output) >= $limit) {
                        $truncated = true;
                    }
                }
            }

            if (!$status['running']) {
                // 退出码只在进程结束后的第一次读取有效，先存下来
                $exitCode = (int)$status['exitcode'];
                break;
            }

            if (microtime(true) - $start > $timeout) {
                proc_terminate($process, 9);
                $timedOut = true;
                $exitCode = self::EXIT_TIMEOUT;
                break;
            }

            usleep(100_000);
        }

        // 进程退出后管道里可能还有残留（尤其被 kill 时）
        foreach ([1, 2] as $index) {
            $rest = stream_get_contents($pipes[$index]);
            if (is_string($rest) && $rest !== '' && strlen($output) < $limit) {
                $output .= substr($rest, 0, $limit - strlen($output));
            }
            fclose($pipes[$index]);
        }

        proc_close($process);

        $output = self::sanitize($output);

        if ($truncated) {
            $output .= "\n" . I18n::t('terminal.output_truncated', ['limit' => $limit]);
        }
        if ($timedOut) {
            $output .= "\n" . I18n::t('terminal.timeout', ['seconds' => $timeout]);
        }

        return [
            'exit_code' => $exitCode,
            'output'    => trim($output),
            'cost'      => (int)round((microtime(true) - $start) * 1000),
            'timed_out' => $timedOut,
        ];
    }

    /**
     * 把一条配置归一化成前端契约（非法配置直接丢弃，不让一条坏配置拖垮整页）。
     *
     * @param  mixed $command
     * @return array<string,mixed>|null
     */
    private static function normalize(mixed $command): ?array
    {
        if (!is_array($command)) {
            return null;
        }
        $key = trim((string)($command['key'] ?? ''));
        $argv = $command['argv'] ?? null;
        // key 只允许小写标识符：它会出现在 URL / 幂等键 / 二次确认输入框里
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1 || !is_array($argv) || $argv === []) {
            return null;
        }

        $params = [];
        foreach ((array)($command['params'] ?? []) as $def) {
            if (!is_array($def) || !isset($def['name'], $def['label'], $def['type'])) {
                continue;
            }
            if (!in_array($def['type'], ['int', 'bool', 'text'], true)) {
                continue;
            }
            $params[] = [
                'name'       => (string)$def['name'],
                'label'      => I18n::t((string)$def['label']),
                'type'       => (string)$def['type'],
                'required'   => (bool)($def['required'] ?? false),
                'min'        => isset($def['min']) ? (int)$def['min'] : null,
                'max'        => isset($def['max']) ? (int)$def['max'] : null,
                'pattern'    => isset($def['pattern']) ? (string)$def['pattern'] : null,
                'max_length' => isset($def['max_length']) ? (int)$def['max_length'] : null,
            ];
        }

        return [
            'key'         => $key,
            'label'       => I18n::t((string)($command['label'] ?? 'terminal.unknown')),
            'description' => I18n::t((string)($command['description'] ?? 'terminal.unknown')),
            'group'       => (string)($command['group'] ?? 'other'),
            'risk'        => max(1, min(3, (int)($command['risk'] ?? self::RISK_NORMAL))),
            // argv 模板保留（`{php}` / `{root}` 未展开）：执行时要用它，且它只暴露命令名与开关，
            // 不含服务器路径 —— 前端拿到也不会比 label/description 多泄露什么
            'argv'        => array_values(array_map('strval', $argv)),
            'params'      => $params,
        ];
    }

    /** 布尔参数：只接受明确的真假写法，其余一律拒绝（避免 `--dry-run=no` 被当成 true） */
    private static function boolValue(mixed $raw, string $label): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $value = strtolower(trim((string)$raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new ApiException(I18n::t('terminal.param_invalid', ['name' => $label]), 422);
    }

    /** 工作目录固定在项目根：命令读相对路径（如 runtime/、db/）时才不会随进程 cwd 漂移 */
    private static function workingDir(): string
    {
        return function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
    }

    /** 去掉 ANSI 颜色与 \r：输出要落库并在网页 `<pre>` 里展示，控制符只会变成乱码 */
    private static function sanitize(string $output): string
    {
        $output = str_replace("\r\n", "\n", $output);
        $output = (string)preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $output);

        return str_replace("\r", '', $output);
    }
}
