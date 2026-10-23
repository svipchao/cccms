<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use support\Log;
use Throwable;

/**
 * 轻量国际化：解析当前请求语言并加载 `lang/{locale}/*.php` 消息。
 *
 * 解析优先级：`?lang=` → `Accept-Language` 请求头 → 配置默认语言（`plugin.cccms.app.locale`）。
 *
 * **刻意不把解析结果存进进程级静态属性**：webman 的 worker 是长驻进程，静态属性会跨请求存活，
 * 那样会把「第一个请求的语言」固化成整个进程的语言（见 docs/08 §九）。
 * 因此 `locale()` 每次按当前请求现算；这里只缓存「与请求无关」的语言包文件内容。
 *
 * CLI / 无请求上下文（`request()` 返回 null）时安全降级到默认语言，不报错。
 */
final class I18n
{
    /** 兜底默认语言 */
    public const DEFAULT_LOCALE = 'zh-CN';

    /** 语言显示名（用于前端语言切换入口；未登记的语言直接显示其 value） */
    private const LABELS = [
        'zh-CN' => '简体中文',
        'en-US' => 'English',
    ];

    /** @var array<string,array<string,array<string,mixed>>> locale => 命名空间 => 消息；语言包与请求无关，可安全缓存 */
    private static array $messages = [];

    /**
     * 可用语言列表（label + value），供前端渲染语言切换入口。
     *
     * @return array<int,array{label:string,value:string}>
     */
    public static function locales(): array
    {
        $out = [];
        foreach (self::supportedLocales() as $value) {
            $out[] = ['label' => self::LABELS[$value] ?? $value, 'value' => $value];
        }
        return $out;
    }

    /** 后端支持的 locale 值列表 */
    public static function supportedLocales(): array
    {
        $configured = self::config('plugin.cccms.app.locales', ['zh-CN', 'en-US']);
        $locales    = [];

        if (is_array($configured)) {
            foreach ($configured as $item) {
                if (is_string($item) && $item !== '') {
                    $locales[] = $item;
                }
            }
        }
        if (!in_array(self::DEFAULT_LOCALE, $locales, true)) {
            $locales[] = self::DEFAULT_LOCALE;
        }

        return array_values(array_unique($locales));
    }

    /** 当前请求语言（CLI / 无请求时回落到默认语言） */
    public static function locale(): string
    {
        $request = function_exists('request') ? request() : null;
        if ($request === null) {
            return self::defaultLocale();
        }

        // ① 显式指定：?lang=en-US（便于排查与强制覆盖）
        $query = (string)$request->input('lang', '');
        if ($query !== '') {
            $hit = self::matchLocale($query);
            if ($hit !== null) {
                return $hit;
            }
        }

        // ② 浏览器/客户端偏好
        $hit = self::fromAcceptLanguage((string)$request->header('accept-language', ''));
        if ($hit !== null) {
            return $hit;
        }

        return self::defaultLocale();
    }

    /** 翻译：`I18n::t('auth.bad_credentials', ['count' => 3])` */
    public static function t(string $key, array $params = []): string
    {
        $locale = self::locale();
        $value  = self::lookup($locale, $key);

        // 缺 key 先回落默认语言，避免英文包缺词时直接暴露 key
        if ($value === null && $locale !== self::DEFAULT_LOCALE) {
            $value = self::lookup(self::DEFAULT_LOCALE, $key);
        }

        if ($value === null) {
            // 两边都缺：返回 key 原文并留痕，方便补词（不返回空串，避免界面出现空白）
            Log::warning("[i18n] missing translation key: {$key} (locale={$locale})");
            return $key;
        }

        return self::replace($value, $params);
    }

    /**
     * key 是否存在（当前语言或默认语言命中其一即可）。
     *
     * 供「有翻译才覆盖」的场景使用：如菜单标题、配置项名 —— 内置项在语言包里有 key 就翻译，
     * 管理员自建项（用户录入数据）没有 key 就原样返回。刻意**不写 missing 日志**，
     * 因为「查不到」在这些场景是正常分支，不是缺词。
     */
    public static function has(string $key): bool
    {
        $locale = self::locale();
        if (self::lookup($locale, $key) !== null) {
            return true;
        }

        return $locale !== self::DEFAULT_LOCALE && self::lookup(self::DEFAULT_LOCALE, $key) !== null;
    }

    /** 配置读取的兜底包装：CLI / 配置未加载时不抛错 */
    private static function config(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config($key, $default) : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    /** 默认语言：取自静态配置 `plugin.cccms.app.locale`，非法值回落到 DEFAULT_LOCALE */
    public static function defaultLocale(): string
    {
        $configured = (string)self::config('plugin.cccms.app.locale', self::DEFAULT_LOCALE);
        return self::matchLocale($configured) ?? self::DEFAULT_LOCALE;
    }

    /** 归一化到受支持的语言；无法识别时返回 null */
    private static function matchLocale(string $locale): ?string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return null;
        }
        $lower = strtolower($locale);

        foreach (self::supportedLocales() as $item) {
            if (strtolower($item) === $lower) {
                return $item;
            }
        }
        // 前缀匹配：`zh` → `zh-CN`、`en` → `en-US`；要求至少 2 个字符，避免 `e` 之类误命中
        if (strlen($lower) >= 2) {
            foreach (self::supportedLocales() as $item) {
                if (str_starts_with(strtolower($item), $lower)) {
                    return $item;
                }
            }
        }

        return null;
    }

    /** 解析 Accept-Language（按出现顺序即优先级），取第一个可识别的语言 */
    private static function fromAcceptLanguage(string $header): ?string
    {
        if (trim($header) === '') {
            return null;
        }
        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);
            $hit = self::matchLocale($tag);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /** 按 `命名空间.键名` 取值；命名空间对应 `lang/{locale}/{命名空间}.php` */
    private static function lookup(string $locale, string $key): ?string
    {
        $pos       = strpos($key, '.');
        $namespace = $pos === false ? 'common' : substr($key, 0, $pos);
        $name      = $pos === false ? $key : substr($key, $pos + 1);
        if ($namespace === '' || $name === '') {
            return null;
        }

        $messages = self::loadNamespace($locale, $namespace);
        if (!array_key_exists($name, $messages)) {
            return null;
        }

        $value = $messages[$name];
        return is_scalar($value) ? (string)$value : null;
    }

    /** @return array<string,mixed> */
    private static function loadNamespace(string $locale, string $namespace): array
    {
        if (isset(self::$messages[$locale][$namespace])) {
            return self::$messages[$locale][$namespace];
        }

        // 命名空间只允许字母数字下划线连字符，防止路径穿越
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $namespace)) {
            return [];
        }

        $file = dirname(__DIR__) . '/lang/' . $locale . '/' . $namespace . '.php';

        // 业务插件的语言包：插件共享同一个 `I18n`，但各自维护 `plugin/{插件}/lang/{locale}/{ns}.php`。
        // 只在主插件目录里没有该命名空间时才回退扫描，避免与 cccms 自身的命名空间抢优先级。
        if (!is_file($file)) {
            $candidates = glob(dirname(__DIR__, 2) . '/*/lang/' . $locale . '/' . $namespace . '.php') ?: [];
            $file       = $candidates === [] ? '' : (string)$candidates[0];
        }

        if ($file === '' || !is_file($file)) {
            return self::$messages[$locale][$namespace] = [];
        }

        $loaded = require $file;
        return self::$messages[$locale][$namespace] = is_array($loaded) ? $loaded : [];
    }

    /** @param array<string,mixed> $params */
    private static function replace(string $message, array $params): string
    {
        if ($params === []) {
            return $message;
        }
        return (string)preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use ($params): string {
                $key = $m[1];
                return array_key_exists($key, $params) ? (string)$params[$key] : $m[0];
            },
            $message
        );
    }
}
