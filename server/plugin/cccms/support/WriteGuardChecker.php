<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\support\Scan\PluginFs;

/**
 * 写入口字段白名单检查（防 Mass Assignment，R-02）。
 *
 * 解决的问题：写接口把 `$request->post()` 整数组交给 Logic 的写方法，可写字段集合是
 * **隐式**的（靠模型声明 + `ScopedQuery` 剔除 `hidden` / `readonly` / `mask` / `encrypt`）。
 * 于是「新加一个字段忘了想清楚它能不能被客户端写入」不会在编译期或运行期暴露，
 * 只能靠机器查。
 *
 * 规则 **W1**：Logic 层中，若一个方法的**数组形参**被直接当作整行数据写库
 * （实参出现在 `insert` / `insertGetId` / `insertAll` / `update` / `save` / `replace` 的调用里），
 * 则该方法必须显式声明可写字段白名单 —— 判据是方法体内出现 `FilterInput::only(`，
 * 或者它把数组交给**同类里的一个私有方法**（`self::xxx(`）而那个方法里有 `FilterInput::only(`。
 *
 * 为什么按「数组形参」而不是按「所有数组写入」判定：写关联表的 `insertAll($rows)`
 * 里 `$rows` 由方法内部拼装（不受客户端直接控制），不属于本规则的范围；
 * 而**来自调用方的数组**才是 `$request->post()` 的化身。
 *
 * 为什么允许一层委托：`prepare()` / `pick()` 这类助手除了过滤字段还要做归一化（如
 * JSON 编码、空值处理），把它们拆开只会产生两份实现。规则只额外允许**一跳**，
 * 且助手必须落在同一个文件里，避免规则被无限传递稀释。
 *
 * 逃生口：确实需要整数组透传的方法，在方法体里写 `// @write-guard-ignore` 并说明理由。
 */
final class WriteGuardChecker
{
    /** 把数组整体写入数据库的调用名 */
    private const WHOLE_ROW_WRITES = ['insert', 'insertGetId', 'insertAll', 'update', 'save', 'replace'];

    /** 逃生口标记（需同时写明理由，见类注释） */
    private const IGNORE_MARK = '@write-guard-ignore';

    /**
     * @return array<int,array{level:string,file:string,line:int,msg:string}>
     */
    public static function check(): array
    {
        $findings = [];

        foreach (self::pluginDirs() as $dir) {
            foreach (self::phpFiles($dir . '/app/logic') as $file) {
                self::checkFile($file, $findings);
            }
        }

        return $findings;
    }

    /** @param array<int,array{level:string,file:string,line:int,msg:string}> $findings */
    private static function checkFile(string $file, array &$findings): void
    {
        $content = (string)file_get_contents($file);
        $methods = self::methods($content);

        // 同类私有方法名 => 方法体，用于判定「一层委托」是否最终落到 FilterInput::only
        $helpers = [];
        foreach ($methods as $method) {
            $helpers[$method['name']] = $method['body'];
        }

        foreach ($methods as $method) {
            if ($method['params'] === []) {
                continue;
            }
            if (str_contains($method['body'], self::IGNORE_MARK)) {
                continue;
            }
            if (!self::writesWholeRow($method['params'], $method['body'])) {
                continue;
            }
            if (self::declaresWhitelist($method['body'], $helpers)) {
                continue;
            }

            $findings[] = [
                'level' => 'error',
                'file'  => self::rel($file),
                'line'  => $method['line'],
                'msg'   => "{$method['name']}() 把数组形参整行写库，却没有声明字段白名单："
                    . '请在方法入口调用 FilterInput::only($data, [可写字段…])'
                    . '（确需整数组透传时写 ' . self::IGNORE_MARK . ' 并说明理由）',
            ];
        }
    }

    /**
     * 方法体是否「显式声明了白名单」：自己调用，或委托给同类中一个调用了它的方法。
     *
     * @param array<string,string> $helpers 同类方法名 => 方法体
     */
    private static function declaresWhitelist(string $body, array $helpers): bool
    {
        if (str_contains($body, 'FilterInput::only(')) {
            return true;
        }

        if (!preg_match_all('/self::(\w+)\s*\(/', $body, $matches)) {
            return false;
        }

        foreach ($matches[1] as $name) {
            if (isset($helpers[$name]) && str_contains($helpers[$name], 'FilterInput::only(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 数组形参是否被当作整行数据写入。
     *
     * @param string[] $params 数组形参名列表
     */
    private static function writesWholeRow(array $params, string $body): bool
    {
        $names = implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), $params));

        // 形参可能被「加工后」再入库（如 `insertGetId(TenantContext::stamp('dict_type', $data))`），
        // 因此不能只匹配 `write($data)`，而要允许实参列表里出现该变量；
        // 窗口限定到最近的 `;` / `)` 之前，避免一行的命中把整个方法判为写入。
        return preg_match(
            '/\b(?:' . implode('|', self::WHOLE_ROW_WRITES) . ')\s*\([^;)]{0,300}\$(?:' . $names . ')\b/',
            $body
        ) === 1;
    }

    /**
     * 拆出类方法：名称、数组形参名、方法体正文、起始行号。
     *
     * @return array<int,array{name:string,params:array<int,string>,body:string,line:int}>
     */
    private static function methods(string $content): array
    {
        $pattern = '/(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)[^{;]*\{/';
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($matches[0] as $i => $match) {
            $params = [];
            if (preg_match_all('/\barray\s+\$(\w+)/', $matches[2][$i][0], $pm)) {
                $params = $pm[1];
            }

            $out[] = [
                'name'   => $matches[1][$i][0],
                'params' => $params,
                'body'   => self::braceBody($content, $match[1] + strlen($match[0])),
                'line'   => substr_count($content, "\n", 0, (int)$match[1]) + 1,
            ];
        }

        return $out;
    }

    /**
     * 从 `{` 之后取到配对的 `}`，**跳过字符串与注释**。
     *
     * 朴素的花括号计数会被字符串里的 `{`（如短信参数模板 `{"mobile":"{mobile}"}`）
     * 带偏，导致方法体被吞掉、后续方法集体漏检。
     */
    private static function braceBody(string $content, int $start): string
    {
        $depth = 1;
        $len   = strlen($content);

        for ($i = $start; $i < $len; $i++) {
            $ch   = $content[$i];
            $next = $i + 1 < $len ? $content[$i + 1] : '';

            if ($ch === "'" || $ch === '"') {
                $i = self::skipString($content, $i, $ch);
                continue;
            }
            if ($ch === '/' && ($next === '/' || $next === '*')) {
                $i = self::skipComment($content, $i);
                continue;
            }
            if ($ch === '#') {
                $i = self::skipComment($content, $i);
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start);
                }
            }
        }

        return substr($content, $start);
    }

    /** 返回闭合引号的下标（处理 `\\` 与 `\'` 转义） */
    private static function skipString(string $content, int $i, string $quote): int
    {
        $len = strlen($content);
        for ($j = $i + 1; $j < $len; $j++) {
            if ($content[$j] === '\\') {
                $j++;
                continue;
            }
            if ($content[$j] === $quote) {
                return $j;
            }
        }

        return $len - 1;
    }

    /** 返回注释结束的下标（行注释到行尾，块注释到闭合标记） */
    private static function skipComment(string $content, int $i): int
    {
        $len = strlen($content);
        if ($content[$i] === '/' && ($content[$i + 1] ?? '') === '*') {
            $end = strpos($content, '*/', $i + 2);
            return $end === false ? $len - 1 : $end + 1;
        }

        $end = strpos($content, "\n", $i);

        return $end === false ? $len - 1 : $end;
    }

    // ------------------------------------------------------------------
    // 工具（与 DataScopeChecker 同口径）
    // ------------------------------------------------------------------

    /**
     * CCCMS 插件目录：声明了 `db/menu.php` 的才算业务插件。
     *
     * @return array<int,string>
     */
    private static function pluginDirs(): array
    {
        return PluginFs::pluginDirs();
    }

    /** @return array<int,string> */
    private static function phpFiles(string $dir): array
    {
        return PluginFs::phpFiles($dir);
    }

    /** 转成相对项目根的路径，输出更短 */
    private static function rel(string $path): string
    {
        return PluginFs::rel($path);
    }
}
