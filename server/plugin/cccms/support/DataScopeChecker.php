<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\support\Scan\PluginFs;
use think\facade\Db;
use Throwable;

/**
 * 数据权限接入检查。
 *
 * 解决的问题：数据权限由**模型的全局作用域**提供，一旦「表登记了受控、查询却没走模型」，
 * 界面照常可用、规则照常保存，但实际一个条件都不会加 —— 属于静默失效，只能靠机器查。
 *
 * 五条规则：
 *   R1 控制器不得直连数据库（`Db::name` / `Db::table` / `Db::query`）：
 *      查询下沉到 Logic，控制器直连等于把数据权限关掉；
 *   R2 登记在 `sys_data_scope_table` 的表：
 *      - 代码里必须有对应模型，且不能声明 `$dataScope = false`（登记了受控却不参与 = 规则永远不生效）；
 *      - Logic 层不得再用 `Db::name('该表')` 查询（那条查询不受作用域约束）。
 *   R3 业务插件表（不带全局前缀）必须显式接入隔离（P1-1）：
 *      - 已登记受控表 / 自带 `tenant_id` 列 / 在 `data_scope.exempt_tables` 显式豁免，三者取一；
 *      - 都没有 = 租户不隔离、规则不生效，界面上却看不出异常。
 *   R4 租户口径不能有两套（P2-6）：模型声明 `$tenantScope = true`，其表必须在
 *      `TenantContext::TABLES` 里，否则模型查询与直查路径结果不一致。
 *   R5 隔离表的直查必须收敛租户（P2-6 的另一半）：`support/` 里 `Db::name('隔离表')`
 *      要么带 `tenant_id` / 走 `TenantContext::table()`，要么按主键定位 ——
 *      否则就是「模型一套、直查另一套」，R4 那类问题会从这条缝里再长出来。
 *
 * 主代码里找不到模型的受控表只提示不报错：本仓库是**基础系统**，
 * 其它插件系统在自己部署里登记的业务表（如 `ks_*`）不归 R2 管 —— 它们由 R3 强制「登记或豁免」。
 */
final class DataScopeChecker
{
    /** 控制器里禁止出现的数据库直连入口 */
    private const FORBIDDEN_IN_CONTROLLER = ['Db::name(', 'Db::table(', 'Db::query('];

    /**
     * @return array<int,array{level:string,file:string,line:int,msg:string}>
     */
    public static function check(): array
    {
        $findings = [];

        foreach (self::pluginDirs() as $dir) {
            self::checkControllers($dir, $findings);
        }

        $models = self::models();
        self::checkGuardedTables($models, $findings);
        self::checkTenantTables($models, $findings);
        self::checkTenantDirectQueries($findings);
        self::checkPluginTables($findings);

        return $findings;
    }

    // ------------------------------------------------------------------
    // R1：控制器不得直连数据库
    // ------------------------------------------------------------------

    /** @param array<int,array{level:string,file:string,line:int,msg:string}> $findings */
    private static function checkControllers(string $dir, array &$findings): void
    {
        foreach (self::phpFiles($dir . '/app/controller') as $file) {
            $content = (string)file_get_contents($file);
            foreach (self::FORBIDDEN_IN_CONTROLLER as $needle) {
                foreach (self::matchLines($content, '/' . preg_quote($needle, '/') . '/') as $line) {
                    $findings[] = [
                        'level' => 'error',
                        'file'  => self::rel($file),
                        'line'  => $line,
                        'msg'   => '控制器不得直连数据库（' . rtrim($needle, '(') . '）：查询请下沉到 Logic，否则绕过数据权限',
                    ];
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // R2：受控表必须模型化
    // ------------------------------------------------------------------

    /**
     * @param array<string,array{file:string,participates:bool}>            $models
     * @param array<int,array{level:string,file:string,line:int,msg:string}> $findings
     */
    private static function checkGuardedTables(array $models, array &$findings): void
    {
        try {
            $guarded = DataScope::guardedTables();
        } catch (Throwable $e) {
            $findings[] = [
                'level' => 'error',
                'file'  => '-',
                'line'  => 0,
                'msg'   => '读取受控表失败（数据库不可用？）：' . $e->getMessage(),
            ];

            return;
        }

        foreach ($guarded as $table) {
            if (!isset($models[$table])) {
                $findings[] = [
                    'level' => 'notice',
                    'file'  => '-',
                    'line'  => 0,
                    'msg'   => "受控表 {$table} 在主代码中找不到模型，已跳过（通常属于其它插件系统登记的表）",
                ];
                continue;
            }

            if (!$models[$table]['participates']) {
                $findings[] = [
                    'level' => 'error',
                    'file'  => $models[$table]['file'],
                    'line'  => self::lineOf($models[$table], '$dataScope'),
                    'msg'   => "受控表 {$table} 的模型声明了 \$dataScope = false：登记为受控却不参与数据权限，配好的规则永远不会生效",
                ];
            }
        }

        foreach (self::pluginDirs() as $dir) {
            foreach (self::phpFiles($dir . '/app/logic') as $file) {
                $content = (string)file_get_contents($file);
                foreach ($guarded as $table) {
                    // 没有模型的受控表不属于本系统职责，跳过
                    if (!isset($models[$table])) {
                        continue;
                    }
                    $pattern = '/Db::(?:name|table)\(\s*\'' . preg_quote($table, '/') . '\'\s*\)/';
                    foreach (self::matchLines($content, $pattern) as $line) {
                        $findings[] = [
                            'level' => 'error',
                            'file'  => self::rel($file),
                            'line'  => $line,
                            'msg'   => "受控表 {$table} 仍在 Logic 里用 Db::name 查询：该查询不经过模型作用域，不受数据权限约束",
                        ];
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // R3：业务插件表必须显式接入隔离
    // ------------------------------------------------------------------

    /**
     * 业务插件表（`plugin/{name}/db/schema.sql` 声明、且不带全局前缀，如 `ks_*`）
     * 默认既没有 `tenant_id` 列，也不在 `sys_data_scope_table` 里，于是
     * **租户不隔离、数据规则也不生效**，而界面一切正常 —— 只能靠机器查（P1-1 方案 B）。
     *
     * 三条通过路径（任一即可）：
     *   1. 已登记为受控表（`sys_data_scope_table`，规则才会作用于它）；
     *   2. 表自带 `tenant_id` 列（租户边界由 `TenantContext` 覆盖，见 `TABLES` 白名单）；
     *   3. 在 `plugin.cccms.data_scope.exempt_tables` 显式豁免（纯字典 / 日志类表）。
     *
     * @param array<int,array{level:string,file:string,line:int,msg:string}> $findings
     */
    private static function checkPluginTables(array &$findings): void
    {
        $prefix = (string)Db::connect()->getConfig('prefix');

        // 只认「插件自己的 db/schema.sql 声明过」的表：同一个库里可能还躺着别的系统的表
        // （例如 Python 版的 alembic_version），按「无前缀 = 插件表」一刀切会误报。
        $tables = array_values(array_filter(
            self::declaredTables(),
            static fn (string $table): bool => $prefix === '' || !str_starts_with($table, $prefix)
        ));
        if ($tables === []) {
            return;
        }

        try {
            $placeholder = implode(',', array_fill(0, count($tables), '?'));
            $rows = Db::query(
                "SELECT t.TABLE_NAME AS name,
                        MAX(CASE WHEN c.COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) AS has_tenant
                   FROM information_schema.TABLES t
                   LEFT JOIN information_schema.COLUMNS c
                     ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
                    AND c.TABLE_NAME = t.TABLE_NAME
                  WHERE t.TABLE_SCHEMA = DATABASE()
                    AND t.TABLE_TYPE = 'BASE TABLE'
                    AND t.TABLE_NAME IN ({$placeholder})
                  GROUP BY t.TABLE_NAME",
                $tables
            );
        } catch (Throwable $e) {
            $findings[] = [
                'level' => 'error',
                'file'  => '-',
                'line'  => 0,
                'msg'   => '读取库表失败（数据库不可用？）：' . $e->getMessage(),
            ];

            return;
        }

        try {
            $guarded = DataScope::guardedTables();
        } catch (Throwable) {
            $guarded = [];
        }

        $exempt = array_map('strval', (array)config('plugin.cccms.data_scope.exempt_tables', []));

        // 只报「库里真实存在」的表：插件声明了但尚未建表时不构成泄露
        foreach ((array)$rows as $row) {
            $full = (string)($row['name'] ?? '');
            if ($full === '') {
                continue;
            }

            if (in_array($full, $guarded, true)
                || (int)($row['has_tenant'] ?? 0) === 1
                || in_array($full, $exempt, true)) {
                continue;
            }

            $findings[] = [
                'level' => 'error',
                'file'  => '-',
                'line'  => 0,
                'msg'   => "插件表 {$full} 未接入隔离：既没登记受控表，也没有 tenant_id 列 —— "
                    . '多租户下 A 租户能读到 B 租户的数据，配好的数据规则也不会作用于它。'
                    . '请登记受控表并配规则、给表加 tenant_id，'
                    . '或在 plugin.cccms.data_scope.exempt_tables（环境变量 DATA_SCOPE_EXEMPT_TABLES）显式豁免',
            ];
        }
    }

    /**
     * 各业务插件 `db/schema.sql` 里声明过的表名（完整表名，含前缀）。
     *
     * @return array<int,string>
     */
    private static function declaredTables(): array
    {
        $tables = [];
        foreach (self::pluginDirs() as $dir) {
            $file = $dir . '/db/schema.sql';
            if (!is_file($file)) {
                continue;
            }
            $sql = (string)file_get_contents($file);
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }
        }

        return array_keys($tables);
    }

    // ------------------------------------------------------------------
    // R4：租户口径不能有两套（模型声明 vs 直查白名单）
    // ------------------------------------------------------------------

    /**
     * 模型声明了 `$tenantScope = true`，其表就必须在 `TenantContext::TABLES` 里。
     *
     * 两者服务不同路径：模型查询靠全局作用域，查询构造器直查靠 `TABLES` 白名单。
     * 只改一处 → 「列表自动过滤了、直查路径却全量」——同一个模块两种结果（P2-6）。
     *
     * @param array<string,array{file:string,participates:bool,tenant:bool}>  $models
     * @param array<int,array{level:string,file:string,line:int,msg:string}>  $findings
     */
    private static function checkTenantTables(array $models, array &$findings): void
    {
        foreach ($models as $table => $model) {
            if (!$model['tenant'] || TenantContext::participates($table)) {
                continue;
            }

            $findings[] = [
                'level' => 'error',
                'file'  => $model['file'],
                'line'  => self::lineOf($model, '$tenantScope'),
                'msg'   => "模型声明了 \$tenantScope = true，但 TenantContext::TABLES 里没有 {$table}："
                    . '模型查询会带租户条件，Db::name / TenantContext::table 的直查路径却不会 —— 同一模块两种结果',
            ];
        }
    }

    // ------------------------------------------------------------------
    // R5：隔离表的直查必须收敛租户
    // ------------------------------------------------------------------

    /**
     * `support/` 里对**参与租户隔离的表**的 `Db::name()` 直查，必须满足其一：
     *   1. 收敛到租户：窗口内出现 `tenant_id` 或 `TenantContext::table(`；
     *   2. 按主键定位：`where('id'` / `whereIn('id'` —— 已收敛到具体行，读不到别人的数据。
     *
     * 为什么只扫 `support/`：业务层已尽量模型化，模型查询由 `BaseModel` 的全局作用域兜住；
     * 基础设施层（`AuthService` / `DataScope` / `CrontabRunner`）才是直查的集中地，
     * 也是「模型一套、直查另一套」最容易发生的地方 —— R4 那个坑就是从这儿漏出来的。
     *
     * 只看**同一条语句的链式调用窗口**（匹配行 + 后续 3 行），不做跨函数数据流推断：
     * 静态扫描宁可放过，也不要制造大量误报把 `--check` 变成「狼来了」。
     *
     * @param array<int,array{level:string,file:string,line:int,msg:string}> $findings
     */
    private static function checkTenantDirectQueries(array &$findings): void
    {
        $tables = array_values(array_filter(
            TenantContext::TABLES,
            static fn (string $table): bool => TenantContext::participates($table)
        ));

        // 视为「已收敛」的三种写法：租户条件、租户直查入口、主键定位
        $safe = '/tenant_id|TenantContext::table\(|where(?:In)?\(\s*\'id\'/';

        foreach (self::pluginDirs() as $dir) {
            foreach (self::phpFiles($dir . '/support') as $file) {
                $content = (string)file_get_contents($file);

                foreach ($tables as $table) {
                    $pattern = '/Db::name\(\s*\'' . preg_quote($table, '/') . '\'\s*\)/';
                    if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                        continue;
                    }

                    foreach ($matches[0] as $match) {
                        // 从匹配点往后取 400 字符：足够覆盖同一条链式调用的其余部分，
                        // 又不至于跨到下一个语句（跨了只会漏报，不会误报）
                        $offset = (int)$match[1];
                        if (preg_match($safe, substr($content, $offset, 400))) {
                            continue;
                        }

                        $findings[] = [
                            'level' => 'error',
                            'file'  => self::rel($file),
                            'line'  => substr_count($content, "\n", 0, $offset) + 1,
                            'msg'   => "隔离表 {$table} 的直查既没有收敛到租户（tenant_id / TenantContext::table），"
                                . '也没有按主键定位：这条查询会看到全部租户的数据，与模型查询的全局作用域形成两套口径',
                        ];
                    }
                }
            }
        }
    }

    /**
     * 扫描所有模型的表名与数据权限声明。
     *
     * @return array<string,array{file:string,participates:bool,tenant:bool}>
     */
    private static function models(): array
    {
        $out = [];
        foreach (self::pluginDirs() as $dir) {
            foreach (self::phpFiles($dir . '/app/model') as $file) {
                $content = (string)file_get_contents($file);
                if (str_contains($content, 'abstract class')) {
                    continue; // BaseModel 这类基类
                }
                // `$name` 会拼连接前缀；插件表（cms_* / member_*）必须用 `$table` 写完整表名，
                // 两种声明都要认得，否则插件模型在 R2 / R4 里等于隐形
                if (!preg_match('/protected\s+\$(?:name|table)\s*=\s*\'([^\']+)\'/', $content, $m)) {
                    continue;
                }
                $out[$m[1]] = [
                    'file'         => self::rel($file),
                    'participates' => !preg_match('/protected\s+\$dataScope\s*=\s*false\s*;/', $content),
                    'tenant'       => (bool)preg_match('/protected\s+\$tenantScope\s*=\s*true\s*;/', $content),
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    /**
     * CCCMS 插件目录：与 PermScanner 口径一致 —— 声明了 `db/menu.php` 的才算业务插件。
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

    /**
     * 正则命中的行号列表。
     *
     * @return array<int,int>
     */
    private static function matchLines(string $content, string $pattern): array
    {
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $lines = [];
        foreach ($matches[0] as $match) {
            $lines[] = substr_count($content, "\n", 0, (int)$match[1]) + 1;
        }

        return $lines;
    }

    /** @param array{file:string,participates:bool} $model */
    private static function lineOf(array $model, string $needle): int
    {
        $full = base_path() . '/../' . $model['file'];
        if (!is_file($full)) {
            return 0;
        }
        $lines = self::matchLines((string)file_get_contents($full), '/' . preg_quote($needle, '/') . '/');

        return $lines[0] ?? 0;
    }

    /** 转成相对项目根的路径，输出更短 */
    private static function rel(string $path): string
    {
        return PluginFs::rel($path);
    }
}
