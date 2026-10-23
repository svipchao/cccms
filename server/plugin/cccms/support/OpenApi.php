<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use ReflectionClass;
use ReflectionMethod;
use Throwable;
use Webman\Route;

/**
 * 由「路由表 + 权限注解 + 控制器源码」生成 OpenAPI 3.1 文档（F1）。
 *
 * ## 为什么以路由表为唯一入口
 *
 * 本项目 `Route::disableDefaultRoute()`，只有 `config/route.php`（及生成器产出的
 * `config/route/*.php`）里声明过的路径可访问 —— 路由表就是**接口清单**。
 * 因此文档从 `Route::getRoutes()` 反查控制器，而不是反过来扫控制器：这样「写了方法
 * 但没挂路由」的接口不会出现在文档里（它本来就调不到），而「挂了路由但方法不存在」
 * 会被 `check()` 直接判错。
 *
 * ## 参数是怎么来的
 *
 * 没有额外的参数注解体系，直接从方法体里读 `$request->get('x')` / `$request->post('x')`：
 * 能拿到名字、`(int)` 之类的类型转换、以及「有没有默认值」（= 是否必填）。
 * 遇到 `$request->get()`（不带参数，整包透传给 Logic）时无法静态枚举，标记
 * `unbounded`，前端提示「该接口接受任意查询参数」，不做假精确。
 *
 * 整包透传的情况再往下一层：顺着控制器方法体里调用的 `XxxLogic::method()`
 * 读 Logic 方法体里的 `$params['x']`（以及 `PageInput::of()` 隐含的 page / limit），
 * 把常见参数补出来。仍然是启发式，所以带 `unbounded` 标记，前端如实提示「可能不全」。
 *
 * ## 枚举值 / 返回字段是怎么来的
 *
 * 值域不在 PHP 里重复声明，直接读库表（见 `support/SchemaMeta`）：
 *   - 请求参数：`CHECK (col IN (...))` + 列注释标签 → `enum: [{value,label}]`；
 *   - 返回字段：由「控制器 → Logic → 模型/表名」推出该接口的主表（拿不到就不猜），
 *     按表结构列出字段、类型与枚举。
 *
 * 主表推断只在 GET 上启用（写接口返回的是 id / null，列表化字段会误导），
 * 且推断结果随详情下发（`table` / `response.inferred`），前端据此提示「按表结构推断」。
 * 代价同样是启发式：`check()` 只把「结构性不一致」当错误，参数覆盖度不做门禁
 * （否则会逼着大家给启发式打补丁）。
 */
final class OpenApi
{
    public const SPEC_VERSION = '3.1.0';

    /**
     * 文档元数据缓存：`控制器@方法#HTTP` => doc。
     *
     * `operations()` 在树、详情、spec 三个入口各跑一遍，`build()` 还要遍历全部操作；
     * 每次反射 + 读源码文件会让文档页在几百条路由下明显变慢。
     *
     * @var array<string,array<string,mixed>>
     */
    private static array $docs = [];

    /** 源码文件缓存：`文件路径` => 行数组 */
    private static array $lines = [];

    /** 插件显示名缓存：`插件名` => 标题（解析要 include 配置文件，别每个分组都来一次） */
    private static array $pluginTitles = [];

    /**
     * 全部操作（一条路由 + 一个 HTTP 方法 = 一条记录）。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function operations(): array
    {
        $items = [];
        foreach (Route::getRoutes() as $route) {
            $callback = $route->getCallback();
            // 闭包路由没有可反射的目标，无法生成文档（当前代码里没有）
            if (!is_array($callback) || count($callback) !== 2 || !is_string($callback[0])) {
                continue;
            }
            [$controller, $action] = $callback;
            if (!is_string($action)) {
                continue;
            }

            $meta = PermissionMeta::of($controller, $action);
            $path = $route->getPath();
            foreach ($route->getMethods() as $method) {
                $doc = self::docOf($controller, $action, $path, $method);

                $items[] = [
                    'id' => $method . ' ' . $path,
                    'method' => $method,
                    'path' => $path,
                    'controller' => $controller,
                    'action' => $action,
                    'plugin' => self::pluginOf($controller),
                    'slug' => $meta['slug'],
                    'summary' => self::summaryOf($meta['slug'], $meta['title'], $action),
                    'group' => self::groupKey($controller, $meta['slug'], $meta['group']),
                    'noAuth' => (bool)$meta['noAuth'],
                    'noLogin' => (bool)$meta['noLogin'],
                    'params' => $doc['params'],
                    'unbounded' => $doc['unbounded'],
                    'table' => $doc['table'],
                    'response' => $doc['response'],
                ];
            }
        }

        // 无 slug 的公开接口（如 `GET /config/ui`）默认按控制器归到 `common:Config`，
        // 但同控制器下已有带 slug 的动作时，它们其实属于同一个业务模块 —— 并入后者，
        // 否则文档里会同时出现「配置管理」和「Config」两个组，看着像两套接口。
        $groupByController = [];
        foreach ($items as $item) {
            if ($item['slug'] !== null && !isset($groupByController[$item['controller']])) {
                $groupByController[$item['controller']] = $item['group'];
            }
        }
        foreach ($items as &$item) {
            if ($item['slug'] === null && isset($groupByController[$item['controller']])) {
                $item['group'] = $groupByController[$item['controller']];
            }
        }
        unset($item);

        // 排序键带上插件：内置插件（cccms）排最前，其余按插件名 —— 前端第一层就是插件，
        // 这里排好序，前端不需要再关心插件顺序。
        usort(
            $items,
            static fn (array $a, array $b): int => [
                ($a['plugin'] ?? '') === 'cccms' ? 0 : 1, $a['plugin'], $a['group'], $a['path'], $a['method'],
            ] <=> [
                ($b['plugin'] ?? '') === 'cccms' ? 0 : 1, $b['plugin'], $b['group'], $b['path'], $b['method'],
            ]
        );

        return $items;
    }

    /**
     * 分组树（供文档页左侧导航；不带 controller/action 等重字段）。
     *
     * 层级为 **插件 → 分组 → 接口**：分组上带 `plugin` / `pluginTitle`，前端据此拼三层树。
     * 不直接在服务端嵌套，是为了让「分组」这个粒度保持稳定 —— 详情、权限节点、
     * 分组标题都按 group key 定位，嵌套会把同一份信息表达两遍。
     *
     * @return array<int,array{key:string,title:string,plugin:string,pluginTitle:string,operations:array<int,array<string,mixed>>}>
     */
    public static function tree(): array
    {
        $groups = [];
        foreach (self::operations() as $item) {
            $key = (string)$item['group'];
            $groups[$key] ??= [
                'key' => $key,
                'title' => self::groupTitle($key),
                'plugin' => (string)$item['plugin'],
                'pluginTitle' => self::pluginTitle((string)$item['plugin']),
                'operations' => [],
            ];
            $groups[$key]['operations'][] = [
                'id' => $item['id'],
                'method' => $item['method'],
                'path' => $item['path'],
                'summary' => $item['summary'],
                'slug' => $item['slug'],
                'noLogin' => $item['noLogin'],
                'noAuth' => $item['noAuth'],
            ];
        }

        // 与 operations() 同口径：cccms 优先，其余按插件名，再按分组 key
        uksort($groups, static function (string $a, string $b) use ($groups): int {
            $left = $groups[$a];
            $right = $groups[$b];

            return [
                $left['plugin'] === 'cccms' ? 0 : 1, $left['plugin'], $a,
            ] <=> [
                $right['plugin'] === 'cccms' ? 0 : 1, $right['plugin'], $b,
            ];
        });

        return array_values($groups);
    }

    /**
     * 单条操作详情（按 `GET /user` 这种 id 取）。
     *
     * @return array<string,mixed>|null
     */
    public static function detail(string $id): ?array
    {
        foreach (self::operations() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 生成完整的 OpenAPI 3.1 文档。
     *
     * @return array<string,mixed>
     */
    public static function build(string $serverUrl = '/api'): array
    {
        $paths = [];
        $tags = [];
        $tagsSeen = [];
        $usedTables = [];

        foreach (self::operations() as $item) {
            $group = (string)$item['group'];
            if (!isset($tagsSeen[$group])) {
                $tagsSeen[$group] = true;
                $tags[] = ['name' => $group, 'description' => self::groupTitle($group)];
            }

            $response = [
                'description' => '统一信封：code / message / data',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/Envelope'],
                    ],
                ],
            ];

            // 推断出主表的 GET 接口：data 指向该表的 schema，导入方能看到字段与枚举
            $table = $item['response']['table'] ?? null;
            if ($table !== null) {
                $usedTables[$table] = true;
                $ref = '#/components/schemas/Table.' . $table;
                $response['description'] = '统一信封；data 为 ' . $table
                    . ' 的行或行数组（按库表结构推断）';
                $response['content']['application/json']['schema'] = [
                    'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                    'properties' => [
                        'data' => [
                            'oneOf' => [
                                ['$ref' => $ref],
                                ['type' => 'array', 'items' => ['$ref' => $ref]],
                            ],
                        ],
                    ],
                ];
            }

            $operation = [
                'operationId' => self::operationId($item),
                'summary' => $item['summary'],
                'tags' => [$group],
                'security' => $item['noLogin'] || $item['noAuth'] ? [] : [['bearerAuth' => []]],
                'responses' => ['200' => $response],
            ];

            if ($item['slug'] !== null) {
                $operation['description'] = '权限节点：`' . $item['slug'] . '`';
                $operation['x-permission'] = $item['slug'];
            }
            if ($table !== null) {
                $operation['x-response-table'] = $table;
            }

            $query = [];
            $body = [];
            foreach ($item['params'] as $param) {
                $schema = ['type' => $param['type']];
                if (!empty($param['enum'])) {
                    $schema['enum'] = array_column($param['enum'], 'value');
                    $schema['x-enum-labels'] = self::enumLabels($param['enum']);
                }

                if ($param['in'] === 'body') {
                    $body[$param['name']] = $schema;
                    continue;
                }

                $spec = [
                    'name' => $param['name'],
                    'in' => 'query',
                    'required' => $param['required'],
                    'schema' => $schema,
                ];
                if (!empty($param['description'])) {
                    $spec['description'] = $param['description'];
                }
                $query[] = $spec;
            }
            if ($query !== []) {
                $operation['parameters'] = $query;
            }
            if ($body !== []) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => $body,
                    ]]],
                ];
            }

            $paths[$item['path']][strtolower((string)$item['method'])] = $operation;
        }

        return [
            'openapi' => self::SPEC_VERSION,
            'info' => [
                'title' => 'CCCMS 管理接口',
                'version' => '1.0.0',
                'description' => '由插件路由表与控制器权限注解自动生成；参数从方法体静态推断，'
                    . 'unbounded 接口（整包透传）列出的参数来自业务层源码，可能不全。'
                    . '枚举值与返回字段按库表 CHECK 约束 / 列注释推断，见 x-enum-labels 与 Table.* schema。',
            ],
            'servers' => [['url' => $serverUrl, 'description' => '前端默认代理前缀']],
            'tags' => $tags,
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
                'schemas' => self::schemas(array_keys($usedTables)),
            ],
        ];
    }

    /**
     * 一致性校验（`cccms:openapi-check` 用，返回非空即门禁失败）。
     *
     * 只查**结构性**问题，能明确判定的才报：
     *   ① 路由指向的控制器方法不存在；
     *   ② 控制器里声明了 #[Permission] 却没有任何路由能调到它（前端点不到、文档也没有）；
     *   ③ 非 NoLogin 路由缺少鉴权属性（fail-closed，与 perm-scan 口径一致）；
     *   ④ slug 格式非法或被多个动作占用。
     *
     * @return array<int,string>
     */
    public static function check(): array
    {
        $errors = [];
        $routed = [];
        $slugs = [];

        foreach (self::operations() as $item) {
            $where = $item['controller'] . '::' . $item['action']
                . '（' . $item['method'] . ' ' . $item['path'] . '）';

            if (!method_exists($item['controller'], $item['action'])) {
                $errors[] = "[路由] {$where} 指向的方法不存在";
                continue;
            }
            $routed[$item['controller'] . '@' . $item['action']] = true;

            // 只对「CCCMS 业务插件」强制 fail-closed：`plugin/index` 这类公开前台插件
            // 本就不声明鉴权注解，压低噪音的标准与 PermScanner::pluginScanners 一致
            if (self::guarded($item['controller'])
                && $item['slug'] === null
                && !$item['noLogin']
                && !$item['noAuth']
            ) {
                $errors[] = "[fail-closed] {$where} 未声明 #[Permission]/#[NoAuth]/#[NoLogin]";
            }
            if ($item['slug'] !== null) {
                if (!preg_match(PermScanner::SLUG_PATTERN, (string)$item['slug'])) {
                    $errors[] = "[格式] {$where} slug '{$item['slug']}' 非法（需 a:b:c 及以上，全小写）";
                }
                if (isset($slugs[$item['slug']])) {
                    $errors[] = "[唯一] slug '{$item['slug']}' 重复：{$slugs[$item['slug']]} 与 {$where}";
                }
                $slugs[$item['slug']] = $where;
            }
        }

        foreach (PermScanner::pluginScanners() as $scanner) {
            foreach ($scanner->scan() as $item) {
                if ($item['slug'] === null) {
                    continue;
                }
                $key = $item['controller'] . '@' . $item['action'];
                if (!isset($routed[$key])) {
                    $errors[] = '[未挂路由] ' . $item['controller'] . '::' . $item['action']
                        . ' 声明了 ' . $item['slug'] . ' 但没有任何路由指向它（前端调不到，文档也不会出现）';
                }
            }
        }

        return $errors;
    }

    /**
     * 单条操作的文档元数据（反射 / 源码解析结果按 控制器@方法#HTTP 缓存）。
     *
     * @return array{params:array<int,array<string,mixed>>,unbounded:bool,table:string|null,response:array<string,mixed>}
     */
    private static function docOf(string $controller, string $action, string $path, string $method): array
    {
        $key = $controller . '@' . $action . '#' . strtoupper($method);
        if (isset(self::$docs[$key])) {
            return self::$docs[$key];
        }

        $params = self::paramsOf($controller, $action);
        $table = self::tableOf($controller, $action, $path);

        // 整包透传：参数在 Logic 里才被拆开，顺着往下再读一层
        $items = $params['items'];
        if ($params['unbounded']) {
            $items = self::mergeParams($items, self::logicParamsOf($controller, $action));
        }

        $enriched = [];
        foreach ($items as $param) {
            $enriched[] = self::enrichParam($param, $table);
        }

        // 返回字段只对 GET 推断：写接口返回的是 id / null，列出整表字段会误导
        $response = ['table' => null, 'inferred' => false, 'fields' => []];
        if (strtoupper($method) === 'GET' && $table !== null) {
            $fields = [];
            foreach (SchemaMeta::table($table) as $name => $column) {
                $fields[] = [
                    'name' => $name,
                    'type' => $column['type'],
                    'comment' => $column['comment'],
                    'enum' => $column['enum'],
                ];
            }
            $response = ['table' => $table, 'inferred' => true, 'fields' => $fields];
        }

        return self::$docs[$key] = [
            'params' => $enriched,
            'unbounded' => $params['unbounded'],
            'table' => $table,
            'response' => $response,
        ];
    }

    /**
     * 方法体里能静态读到的请求参数。
     *
     * @return array{items:array<int,array{name:string,in:string,type:string,required:bool}>,unbounded:bool}
     */
    private static function paramsOf(string $controller, string $action): array
    {
        $body = self::methodBody($controller, $action);
        if ($body === null) {
            return ['items' => [], 'unbounded' => false];
        }

        // 整包透传：参数在 Logic 里才被拆开，这里只能先拿参数名
        $unbounded = (bool)preg_match('/[$]request->(?:get|post|input|all)\(\s*\)/', $body);

        $items = [];
        $seen = [];
        preg_match_all(
            '/[$]request->(get|post|input|all)\(\s*[\'"]([\w.]+)[\'"]\s*(?:,\s*([^)]*))?\)/',
            $body,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $accessor = $match[1][0];
            $name = $match[2][0];
            $hasDefault = isset($match[3]) && (int)$match[3][1] >= 0 && trim($match[3][0]) !== '';
            $in = $accessor === 'post' ? 'body' : 'query';
            $key = $in . ':' . $name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'name' => $name,
                'in' => $in,
                'type' => self::inferType($body, (int)$match[0][1]),
                // 有默认值 = 可省略；没有默认值就是必填
                'required' => !$hasDefault,
            ];
        }

        return ['items' => $items, 'unbounded' => $unbounded];
    }

    /**
     * 控制器把整个查询串透传给 Logic 时，从 Logic 方法体里补参数。
     *
     * 只读签名里声明为数组的参数（`$params['x']`），并且只取 query —— 写接口的
     * `$request->post()` 透传走 `$data['x']` 且中间常经过白名单，静态读出来也未必准，
     * 索性不猜。`PageInput::of()` 隐含 page / limit，单独补上。
     *
     * @return array<int,array{name:string,in:string,type:string,required:bool}>
     */
    private static function logicParamsOf(string $controller, string $action): array
    {
        $found = [];
        foreach (self::logicTargets($controller, $action) as $target) {
            self::collectParams($target['class'], $target['method'], $found, 0);
        }

        return array_values($found);
    }

    /**
     * 收集一个 Logic 方法（含它 `self::helper($params)` 调用的同族方法）读取的参数。
     *
     * 递归限两跳：`paginate()` 把过滤条件委托给 `filtered($params)` 是常见写法，
     * 再往下就是层层抽象，读出来的东西也不再可信。
     *
     * @param array<string,array{name:string,in:string,type:string,required:bool}> $found
     */
    private static function collectParams(string $class, string $method, array &$found, int $depth): void
    {
        $body = self::methodBody($class, $method);
        if ($body === null) {
            return;
        }

        $vars = self::arrayParamVars($class, $method);
        foreach ($vars as $var) {
            preg_match_all('/[$]' . preg_quote($var, '/') . '\s*\[\s*[\'"]([\w]+)[\'"]\s*\]/', $body, $m);
            foreach ($m[1] as $name) {
                $found[$name] ??= ['name' => $name, 'in' => 'query', 'type' => 'string', 'required' => false];
            }
        }

        if (str_contains($body, 'PageInput::of(')) {
            $found['page'] ??= ['name' => 'page', 'in' => 'query', 'type' => 'integer', 'required' => false];
            $found['limit'] ??= ['name' => 'limit', 'in' => 'query', 'type' => 'integer', 'required' => false];
        }

        if ($depth >= 2) {
            return;
        }

        foreach ($vars as $var) {
            preg_match_all('/self::(\w+)\s*\(\s*[$]' . preg_quote($var, '/') . '\s*[,)]/', $body, $m);
            foreach (array_unique($m[1]) as $next) {
                self::collectParams($class, $next, $found, $depth + 1);
            }
        }
    }

    /** 合并参数列表（同名同位置只保留先出现的） */
    private static function mergeParams(array $base, array $extra): array
    {
        $seen = [];
        foreach ($base as $param) {
            $seen[$param['in'] . ':' . $param['name']] = true;
        }
        foreach ($extra as $param) {
            $key = $param['in'] . ':' . $param['name'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $base[] = $param;
        }

        return $base;
    }

    /**
     * 给参数补枚举值 / 说明。
     *
     * 优先用「该接口主表」的同名列；拿不到主表时退回跨表口径一致的列
     * （`status` / `type` 各表不同，`sharedEnum()` 会返回空，不写错的值域）。
     *
     * @param  array{name:string,in:string,type:string,required:bool} $param
     * @return array<string,mixed>
     */
    private static function enrichParam(array $param, ?string $table): array
    {
        $column = $table !== null ? (SchemaMeta::table($table)[$param['name']] ?? null) : null;

        if ($column === null) {
            $enum = SchemaMeta::sharedEnum($param['name']);
            if ($enum !== []) {
                $param['enum'] = $enum;
            }

            return $param;
        }

        if ($column['comment'] !== '') {
            $param['description'] = $column['comment'];
        }
        if ($column['enum'] !== []) {
            $param['enum'] = $column['enum'];
        }
        // 方法体里没有强转可读时（Logic 透传参数），用列类型兜底
        if ($param['type'] === 'string' && $column['type'] !== 'string') {
            $param['type'] = $column['type'];
        }

        return $param;
    }

    /**
     * 推断接口对应的主表。
     *
     * 链路：控制器方法 → `XxxLogic::method()` → 方法体里的模型类 / `Db::name()`。
     * 命中多张表说明是聚合接口（看板、统计），**不猜**，直接放弃返回字段。
     * 没有 Logic 调用（登录、公开接口）时按路由路径映射：`/dict/data` → `sys_dict_data`。
     */
    private static function tableOf(string $controller, string $action, string $path): ?string
    {
        $tables = [];
        foreach (self::logicTargets($controller, $action) as $target) {
            foreach (self::tablesOfLogic($target['class'], $target['method']) as $table) {
                $tables[$table] = true;
            }
        }

        if (count($tables) === 1) {
            return (string)array_key_first($tables);
        }
        if (count($tables) > 1) {
            return null;
        }

        return self::tableFromPath($path);
    }

    /**
     * 路由路径 → 表名：`/crontab/log` 先试 `sys_crontab_log`，再退回 `sys_crontab`。
     *
     * 只接受库里真实存在的表，所以 `/monitor/paths`、`/auth/login` 这类会自然落空。
     */
    private static function tableFromPath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }
            // /dataRule → data_rule（路由里是驼峰，表名是下划线）
            $segments[] = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $segment));
        }

        for ($length = count($segments); $length >= 1; $length--) {
            $table = SchemaMeta::resolve(implode('_', array_slice($segments, 0, $length)));
            if ($table !== null) {
                return $table;
            }
        }

        return null;
    }

    /**
     * 控制器方法体里调用的 `XxxLogic::method(...)`（类名按控制器文件的 use 解析）。
     *
     * @return array<int,array{class:string,method:string}>
     */
    private static function logicTargets(string $controller, string $action): array
    {
        $body = self::methodBody($controller, $action);
        if ($body === null) {
            return [];
        }

        $imports = self::importsOf($controller);
        preg_match_all('/([A-Z]\w*Logic)::(\w+)\s*\(/', $body, $matches, PREG_SET_ORDER);

        $targets = [];
        foreach ($matches as $match) {
            $class = $imports[$match[1]] ?? null;
            if ($class === null) {
                continue;
            }
            $targets[] = ['class' => $class, 'method' => $match[2]];
        }

        return $targets;
    }

    /**
     * Logic 方法里引用的表（模型类 → 模型 `$name` → 真实表名；外加 `Db::name()` / `Db::table()`）。
     *
     * @return array<int,string>
     */
    private static function tablesOfLogic(string $logic, string $method): array
    {
        $body = self::methodBody($logic, $method);
        if ($body === null) {
            return [];
        }

        $imports = self::importsOf($logic);
        $tables = [];

        preg_match_all('/([A-Z]\w*)::/', $body, $matches);
        foreach (array_unique($matches[1]) as $short) {
            $class = $imports[$short] ?? null;
            if ($class === null || !str_contains($class, '\\app\\model\\')) {
                continue;
            }
            $table = self::tableOfModel($class);
            if ($table !== null) {
                $tables[$table] = true;
            }
        }

        preg_match_all('/Db::(?:name|table)\(\s*[\'"]([\w]+)[\'"]/', $body, $matches);
        foreach ($matches[1] as $name) {
            $table = SchemaMeta::resolve($name);
            if ($table !== null) {
                $tables[$table] = true;
            }
        }

        return array_keys($tables);
    }

    /** 模型类 → 真实表名（模型只声明 `$name = 'crontab'`，前缀由连接配置补全） */
    private static function tableOfModel(string $class): ?string
    {
        try {
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
        } catch (Throwable) {
            return null;
        }

        $name = $defaults['name'] ?? null;
        if (!is_string($name) || $name === '') {
            // 没声明 $name 时 think-orm 用类名转下划线（CrontabLog → crontab_log）
            $short = substr($class, (int)strrpos($class, '\\') + 1);
            $name  = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        }

        return SchemaMeta::resolve($name);
    }

    /** 方法从第几个参数开始是数组（`paginate(array $params)` → ['params']） */
    private static function arrayParamVars(string $class, string $method): array
    {
        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return [];
        }

        $vars = [];
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if ($type === null || ($type instanceof \ReflectionNamedType && $type->getName() === 'array')) {
                $vars[] = $param->getName();
            }
        }

        return $vars;
    }

    /** 方法体源码（带文件行缓存：同一请求里同文件会被反射很多次） */
    private static function methodBody(string $class, string $method): ?string
    {
        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null;
        }

        $file  = $ref->getFileName();
        $start = $ref->getStartLine();
        $end   = $ref->getEndLine();
        if ($file === false || $start === false || $end === false || !is_file($file)) {
            return null;
        }

        if (!isset(self::$lines[$file])) {
            $lines = file($file);
            if ($lines === false) {
                return null;
            }
            self::$lines[$file] = $lines;
        }

        return implode('', array_slice(self::$lines[$file], $start - 1, $end - $start + 1));
    }

    /**
     * 文件顶部的 `use` 语句：短类名 => 完整类名。
     *
     * 只解析命名空间级 use（本项目没有 function / const 导入），够定位 Logic 与 Model。
     *
     * @return array<string,string>
     */
    private static function importsOf(string $class): array
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return [];
        }
        if ($file === false || !is_file($file)) {
            return [];
        }

        $source = (string)file_get_contents($file);
        preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

        $imports = [];
        foreach ($matches[1] as $raw) {
            $raw = trim($raw);
            $alias = null;
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $raw, $m) === 1) {
                $raw   = trim($m[1]);
                $alias = $m[2];
            }
            $short = $alias ?? substr($raw, (int)strrpos($raw, '\\') + 1);
            $imports[$short] = $raw;
        }

        return $imports;
    }

    /** 从 `(int)$request->get('x')` 这类前置强转推断参数类型 */
    private static function inferType(string $body, int $offset): string
    {
        $prefix = substr($body, max(0, $offset - 12), 12);

        return match (true) {
            (bool)preg_match('/\(int\)\s*$/', $prefix) => 'integer',
            (bool)preg_match('/\(float\)\s*$/', $prefix) => 'number',
            (bool)preg_match('/\(bool\)\s*$/', $prefix) => 'boolean',
            default => 'string',
        };
    }

    /**
     * 该控制器是否属于「受权限注解约束」的插件。
     *
     * 判定标准与 `PermScanner::pluginScanners()` 完全一致：插件根目录下有 `db/menu.php`
     * 才算 CCCMS 业务插件。两处口径必须同步，否则会出现「perm-scan 通过、openapi-check 失败」。
     */
    private static function guarded(string $controller): bool
    {
        $parts = explode('\\', $controller);
        if (($parts[0] ?? '') !== 'plugin' || !isset($parts[1])) {
            return false;
        }

        return is_file(base_path() . '/plugin/' . $parts[1] . '/db/menu.php');
    }

    /** 控制器属于哪个插件（`plugin\cccms\app\controller\UserController` → `cccms`） */
    private static function pluginOf(string $controller): string
    {
        $parts = explode('\\', $controller);
        if (($parts[0] ?? '') === 'plugin' && isset($parts[1]) && $parts[1] !== '') {
            return $parts[1];
        }

        return 'app';
    }

    /**
     * 插件在文档里显示的名字（三层树的第一层）。
     *
     * 取不到「人话」就退回插件目录名，绝不空着。来源依次为：
     * ① 本模块语言包 `apidoc.plugin_{插件}`（内置插件有中英文名）；
     * ② `plugin/{插件}/config/app.php` 的 `title`（脚手架生成的插件可声明）；
     * ③ `plugin/{插件}/db/menu.php` 第一个一级菜单标题（业务插件通常就是业务名，如「商城」）。
     */
    private static function pluginTitle(string $plugin): string
    {
        return self::$pluginTitles[$plugin] ??= self::resolvePluginTitle($plugin);
    }

    /** @see pluginTitle() */
    private static function resolvePluginTitle(string $plugin): string
    {
        $key = 'apidoc.plugin_' . $plugin;
        if (I18n::has($key)) {
            return I18n::t($key);
        }

        $dir = base_path() . '/plugin/' . $plugin;

        $appConfig = $dir . '/config/app.php';
        if (is_file($appConfig)) {
            try {
                $config = (array)include $appConfig;
                if (isset($config['title']) && is_string($config['title']) && $config['title'] !== '') {
                    return $config['title'];
                }
            } catch (Throwable) {
                // 配置里写了会抛异常的逻辑：忽略，继续往下找
            }
        }

        $menuFile = $dir . '/db/menu.php';
        if (is_file($menuFile)) {
            try {
                foreach ((array)include $menuFile as $node) {
                    $title = is_array($node) ? ($node['title'] ?? '') : '';
                    if (is_string($title) && $title !== '' && empty($node['remove'])) {
                        return $title;
                    }
                }
            } catch (Throwable) {
                // 同上：菜单声明异常不应让文档页挂掉
            }
        }

        return $plugin;
    }

    /**
     * 分组 key（左侧树的第二层，同时是 spec 的 tag）。
     *
     * ① 注解声明了 `group` 就用它 —— 这是**按钮归属菜单**，与 `PermScanner::resolveParent()`
     *    同口径：`cccms:job:*` 挂在「定时任务」下、`cccms:recycle:*` 挂在「系统设置」下，
     *    文档若按 slug 前缀另起 `cccms:job` / `cccms:recycle` 分组，这两个 key 在语言包里
     *    根本没有对应菜单（菜单只有 `cccms:crontab` / `cccms:setting`），标题只能退回 key 原文，
     *    界面里就露出 `cccms:job`。
     * ② 没声明 group 时取权限 slug 前两段（`cccms:user:index` → `cccms:user`）。
     * ③ 公开接口（无 slug）按控制器归组，后续再并入同控制器下的业务分组。
     */
    private static function groupKey(string $controller, ?string $slug, ?string $group = null): string
    {
        if ($group !== null && $group !== '') {
            return $group;
        }

        if ($slug !== null) {
            return Slug::groupOf($slug);
        }

        return 'common:' . self::shortName($controller);
    }

    /**
     * 接口标题（详情页 h3、树节点搜索词、spec 的 operation.summary）。
     *
     * 注解里的 `title` 是**中文原文**（写库与操作日志的兜底值），直接下发会让英文界面里
     * 全是「作业队列列表」「删除作业」这类中文。口径与 `MenuLogic::localizeTitles()` 一致：
     * 有权限节点的走 `menu.{slug}` 命中语言包才覆盖，管理员自建 / 未登记节点原样返回。
     */
    private static function summaryOf(?string $slug, ?string $title, string $action): string
    {
        if ($slug !== null) {
            $key = 'menu.' . $slug;
            if (I18n::has($key)) {
                return I18n::t($key);
            }
        }

        return $title ?: $action;
    }

    /** 分组标题：内置节点走 `menu.{slug}` 语言包，取不到就退回 key / 上下文 */
    private static function groupTitle(string $group, ?string $fallback = null): string
    {
        if (!str_starts_with($group, 'common:')) {
            return I18n::has('menu.' . $group) ? I18n::t('menu.' . $group) : ($fallback ?? $group);
        }

        // 纯公开接口（Auth / Home / Observability 这类控制器没有权限节点）没有菜单可挂，
        // 标题放本模块语言包，避免中文界面里冒出英文控制器名。
        // 先按 `cccms:{控制器}` 找菜单标题（Dashboard 这类接口本身就是菜单），再退回本模块语言包。
        $short = substr($group, 7);
        $menuSlug = 'cccms:' . strtolower($short);
        if (I18n::has('menu.' . $menuSlug)) {
            return I18n::t('menu.' . $menuSlug);
        }

        $key = 'apidoc.group_' . strtolower($short);

        return I18n::has($key) ? I18n::t($key) : $short;
    }

    private static function shortName(string $controller): string
    {
        $name = substr($controller, (int)strrpos($controller, '\\') + 1);

        return preg_replace('/Controller$/', '', $name) ?: $name;
    }

    /** @param array<string,mixed> $item */
    private static function operationId(array $item): string
    {
        $slug = $item['slug'] ?: self::shortName((string)$item['controller']) . '.' . $item['action'];

        return strtolower((string)$item['method']) . ':' . $slug;
    }

    /**
     * 统一信封 + 本次文档实际引用到的表结构。
     *
     * 只注册「有 GET 接口指向它」的表：把整库表都塞进 spec 既臃肿，也等于把没开放的
     * 内部表结构写进文档。
     *
     * @param  array<int,string> $tables 本次文档用到的表名
     * @return array<string,mixed>
     */
    private static function schemas(array $tables = []): array
    {
        $schemas = [
            'Envelope' => [
                'type' => 'object',
                'required' => ['code', 'message'],
                'properties' => [
                    'code' => ['type' => 'integer', 'description' => '0 = 成功；非 0 为业务错误码'],
                    'message' => ['type' => 'string', 'description' => '提示文案（后端已按请求语言翻译）'],
                    'data' => ['description' => '业务数据，结构随接口而定'],
                    'trace_id' => ['type' => 'string', 'description' => '仅错误响应：请求链路 ID'],
                    'action_id' => ['type' => 'string', 'description' => '仅错误响应：操作 ID'],
                    'session_id' => ['type' => 'string', 'description' => '仅错误响应：会话 ID'],
                ],
            ],
        ];

        foreach ($tables as $table) {
            $properties = [];
            foreach (SchemaMeta::table($table) as $name => $column) {
                $property = ['type' => $column['type']];
                if ($column['comment'] !== '') {
                    $property['description'] = $column['comment'];
                }
                if ($column['enum'] !== []) {
                    $property['enum'] = array_column($column['enum'], 'value');
                    $property['x-enum-labels'] = self::enumLabels($column['enum']);
                }
                $properties[$name] = $property;
            }
            if ($properties === []) {
                continue;
            }

            $schemas['Table.' . $table] = [
                'type' => 'object',
                'description' => '按库表 ' . $table . ' 结构推断（可能含接口未返回的列）',
                'properties' => $properties,
            ];
        }

        return $schemas;
    }

    /**
     * 枚举标签映射：`值 => 说明`（OpenAPI 没有 label 概念，用扩展字段承载）。
     *
     * @param  array<int,array{value:mixed,label:string}> $enum
     * @return array<string,string>
     */
    private static function enumLabels(array $enum): array
    {
        $labels = [];
        foreach ($enum as $option) {
            if (($option['label'] ?? '') !== '') {
                $labels[(string)$option['value']] = (string)$option['label'];
            }
        }

        return $labels;
    }
}
