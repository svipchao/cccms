<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Menu;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\Environment;
use plugin\cccms\support\I18n;
use think\facade\Db;

/**
 * 代码生成器。
 *
 * 按库表结构生成 Model / Logic / Controller / 前端页面，
 * 并在 sys_menu 中登记菜单（按钮节点仍由 cccms:perm-scan 依据注解生成）。
 */
final class GeneratorLogic
{
    /** 不参与表单/表格的字段 */
    private const IGNORE_FIELDS = ['id', 'create_time', 'update_time', 'delete_time'];

    /**
     * 环境守卫：生成器会直接写项目文件（php / ts / vue），与在线升级同级风险。
     *
     * 两道闸（目录自检 + `plugin.cccms.generator.enable`）统一由 `Environment::blockedBy()`
     * 判定，任一不过返回 403。与在线升级共用同一入口，不会出现「升级拦住了、生成器没拦住」。
     *
     * 这里不依赖权限节点：即使角色授权配错，生产环境也写不进文件。
     */
    private static function ensureAvailable(): void
    {
        $blockedBy = Environment::blockedBy('generator');

        if ($blockedBy === 'environment') {
            throw new ApiException(I18n::t('generator.disabled_production'), 403);
        }

        if ($blockedBy === 'switch') {
            throw new ApiException(I18n::t('generator.disabled_switch'), 403);
        }
    }

    /**
     * 生成器可用性（页面据此决定渲染表单还是「未启用」提示）。
     *
     * 与 `Upgrader::environment()` 同一口径：页面拿到 `allowed = false` 时直接显示
     * 拦截原因，而不是让用户点进表单、再在调用接口时吃一个 403 弹窗。
     *
     * @return array{key:string,allowed:bool,blocked_by:string,reason:string,marker:string}
     */
    public static function environment(): array
    {
        $blockedBy = Environment::blockedBy('generator');

        if ($blockedBy === 'environment') {
            return [
                'key'        => 'production',
                'allowed'    => false,
                'blocked_by' => 'environment',
                'reason'     => I18n::t('generator.disabled_production'),
                'marker'     => Environment::DEV_MARKER,
            ];
        }

        if ($blockedBy === 'switch') {
            return [
                'key'        => 'dev',
                'allowed'    => false,
                'blocked_by' => 'switch',
                'reason'     => I18n::t('generator.disabled_switch'),
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

    public static function tables(): array
    {
        self::ensureAvailable();

        $rows = Db::query(
            'SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
             ORDER BY TABLE_NAME'
        );
        return array_map(static fn ($r) => [
            'name'    => (string)$r['name'],
            'comment' => (string)($r['comment'] ?? ''),
        ], $rows);
    }

    public static function columns(string $table): array
    {
        self::ensureAvailable();

        $rows = Db::query(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS column_type,
                    IS_NULLABLE AS nullable, COLUMN_COMMENT AS comment, COLUMN_KEY AS `key`
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$table]
        );
        return array_map(static fn ($r) => [
            'name'    => (string)$r['name'],
            'type'    => (string)$r['type'],
            'comment' => (string)($r['comment'] ?? ''),
            'primary' => ($r['key'] ?? '') === 'PRI',
        ], $rows);
    }

    /**
     * 生成预览：返回待写入的文件列表。
     *
     * @param array<string,mixed> $config plugin / module / table / title
     * @return array<int,array{path:string,content:string}>
     */
    public static function preview(array $config): array
    {
        self::ensureAvailable();

        $ctx = self::context($config);
        return [
            ['path' => "server/plugin/{$ctx['plugin']}/app/model/{$ctx['Module']}.php", 'content' => self::tplModel($ctx)],
            ['path' => "server/plugin/{$ctx['plugin']}/app/logic/{$ctx['Module']}Logic.php", 'content' => self::tplLogic($ctx)],
            ['path' => "server/plugin/{$ctx['plugin']}/app/controller/{$ctx['Module']}Controller.php", 'content' => self::tplController($ctx)],
            ['path' => "server/plugin/{$ctx['plugin']}/config/route/{$ctx['module']}.php", 'content' => self::tplRoute($ctx)],
            ['path' => "frontend/src/api/auto/{$ctx['plugin']}/{$ctx['module']}.ts", 'content' => self::tplApi($ctx)],
            ['path' => "frontend/src/pages/{$ctx['plugin']}/{$ctx['module']}/index.vue", 'content' => self::tplVue($ctx)],
        ];
    }

    /**
     * 生成并写盘。
     *
     * @param array<string,mixed> $config
     * @return array{files:array<int,string>,menu:array{slug:string,title:string,path:string,component:string},menu_snippet:string}
     */
    public static function generate(array $config): array
    {
        self::ensureAvailable();

        $ctx = self::context($config);
        $written = [];

        // 项目根目录（server 的上一级）
        $root = dirname(rtrim(base_path(), '/\\'));

        foreach (self::preview($config) as $file) {
            $full = $root . '/' . $file['path'];
            $dir = dirname($full);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new ApiException(I18n::t('generator.dir_create_failed', ['dir' => $dir]), 500);
            }
            if (is_file($full) && empty($config['overwrite'])) {
                throw new ApiException(I18n::t('generator.file_exists', ['path' => $file['path']]), 422);
            }
            if (file_put_contents($full, $file['content']) === false) {
                throw new ApiException(I18n::t('generator.file_write_failed', ['path' => $file['path']]), 500);
            }
            $written[] = $file['path'];
        }

        $menu = self::registerMenu($ctx);

        return [
            'files'        => $written,
            'menu'         => $menu,
            'menu_snippet' => sprintf(
                "['slug' => '%s', 'title' => '%s', 'type' => 2, 'path' => '%s', 'component' => '%s', 'icon' => 'icon-apps', 'sort' => 99],",
                $ctx['menuSlug'],
                $ctx['title'],
                $ctx['path'],
                $ctx['component']
            ),
        ];
    }

    /**
     * 上下文推导：module / Module / slug / path / component。
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function context(array $config): array
    {
        $plugin = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($config['plugin'] ?? 'cccms'))) ?: 'cccms';
        $table  = strtolower(trim((string)($config['table'] ?? '')));
        if ($table === '') {
            throw new ApiException(I18n::t('generator.table_required'), 422);
        }

        // 去掉连接配置里的 sys_ 前缀，作为模型表名
        $prefix = (string)config('plugin.cccms.database.connections.mysql.prefix', 'sys_');
        $bare = ($prefix !== '' && str_starts_with($table, $prefix)) ? substr($table, strlen($prefix)) : $table;

        $module = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($config['module'] ?? $bare))) ?: $bare;
        if ($module === '') {
            throw new ApiException(I18n::t('generator.module_required'), 422);
        }

        $columns = self::columns($table);
        if (!$columns) {
            throw new ApiException(I18n::t('generator.table_not_found', ['table' => $table]), 422);
        }
        $pk = 'id';
        foreach ($columns as $c) {
            if ($c['primary']) {
                $pk = $c['name'];
                break;
            }
        }

        $title = (string)($config['title'] ?? '') ?: $module;

        return [
            'plugin'    => $plugin,
            'table'     => $bare,
            'module'    => $module,
            'Module'    => self::pascal($module),
            'ModuleLogic' => self::pascal($module) . 'Logic',
            'title'     => $title,
            'pk'        => $pk,
            'columns'   => $columns,
            'fields'    => array_values(array_filter($columns, static fn ($c) => !in_array($c['name'], self::IGNORE_FIELDS, true))),
            'menuSlug'  => "{$plugin}:{$module}",
            'path'      => "/{$plugin}/{$module}",
            'component' => "{$plugin}/{$module}/index",
        ];
    }

    /**
     * 数据权限声明（按表结构生成）。
     *
     * 生成器决定的是「新插件的默认路径」，所以必须给出一个**可用**的声明，
     * 而不是留空：留空会退化成「按 create_by / dept_id 走基线」，
     * 表里没有这两列就会拼出不存在的列、查询直接报错。
     *
     * @param array<string,mixed> $c 生成上下文（含 columns）
     * @return array{decl:array<int,string>,extra:array<int,string>,needsDb:bool}
     */
    private static function dataScopeSnippet(array $c): array
    {
        $hasOwner = self::hasColumn($c, 'create_by');
        $hasDept  = self::hasColumn($c, 'dept_id');

        if ($hasOwner && $hasDept) {
            return [
                'decl' => [
                    '',
                    '    /**',
                    '     * 数据权限：表里有 create_by 与 dept_id，按默认语义即可。',
                    '     *   - 仅本人 = create_by 是我；',
                    '     *   - 本部门 = dept_id 落在我可见的部门集合里。',
                    '     */',
                    "    protected \$dataScope = ['owner' => 'create_by'];",
                ],
                'extra'   => [],
                'needsDb' => false,
            ];
        }

        if ($hasOwner) {
            return [
                'decl' => [
                    '',
                    '    /**',
                    '     * 数据权限：有 create_by 但没有 dept_id，',
                    '     * 「本部门」需要把 create_by 落到「可见部门下的用户」。',
                    '     */',
                    '    protected $dataScope = [',
                    "        'owner' => 'create_by',",
                    "        'dept'  => [self::class, 'deptScope'],",
                    '    ];',
                ],
                'extra' => [
                    '',
                    '    /** 「本部门 / 及以下」：把 create_by 落到「可见部门下的用户」 */',
                    '    public static function deptScope($query, array $deptIds): void',
                    '    {',
                    '        $userIds = $deptIds === []',
                    '            ? []',
                    "            : Db::name('user_dept')->whereIn('dept_id', \$deptIds)->column('user_id');",
                    '',
                    '        // fail-closed：没有可匹配的成员时不能放行任何数据',
                    "        \$query->whereIn('create_by', \$userIds ?: [0]);",
                    '    }',
                ],
                'needsDb' => true,
            ];
        }

        return [
            'decl' => [
                '',
                '    /**',
                '     * 数据权限：表里没有 create_by（无法判定归属），已跳过预设基线。',
                '     * TODO 需要按人 / 部门隔离时：补一个归属字段并改成对应声明，或登记为受控表后用自定义规则。',
                '     */',
                "    protected \$dataScope = ['no_baseline' => true];",
            ],
            'extra'   => [],
            'needsDb' => false,
        ];
    }

    /**
     * 生成「可写字段白名单」时要排除的系统维护列。
     *
     * 这些列要么由框架写入（`tenant_id` / `create_*`），要么由调度进程维护
     * （`running*`），经接口改写会造成数据归属错乱或锁不释放。
     */
    private const SYSTEM_COLUMNS = [
        'tenant_id', 'create_time', 'update_time', 'delete_time', 'create_by',
        'login_time', 'login_ip', 'last_run_time', 'next_run_time',
        'running', 'running_at', 'read_count',
    ];

    /**
     * 由表结构推导可写字段（排除主键与系统维护列）。
     *
     * @param  array<string,mixed> $c
     * @return string[]
     */
    private static function writableFields(array $c): array
    {
        $pk = (string)($c['pk'] ?? 'id');

        $out = [];
        foreach ((array)($c['columns'] ?? []) as $column) {
            $name = (string)($column['name'] ?? '');
            if ($name === '' || $name === $pk || in_array($name, self::SYSTEM_COLUMNS, true)) {
                continue;
            }
            $out[] = $name;
        }

        return $out;
    }

    /** @param array<string,mixed> $c */
    private static function hasColumn(array $c, string $name): bool
    {
        foreach ((array)($c['columns'] ?? []) as $column) {
            if (($column['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * 模型模板。
     *
     * 用「逐行拼接」而不是 heredoc：数据权限声明是按表结构拼出来的多行代码，
     * 插进 heredoc 的缩进很难对齐。
     *
     * @param array<string,mixed> $c
     */
    private static function tplModel(array $c): string
    {
        $scope = self::dataScopeSnippet($c);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            "namespace plugin\\{$c['plugin']}\\app\\model;",
            '',
            'use plugin\\cccms\\app\\model\\BaseModel;',
        ];
        if ($scope['needsDb']) {
            $lines[] = 'use think\\facade\\Db;';
        }

        $lines[] = '';
        $lines[] = "/** {$c['title']}（代码生成器生成） */";
        $lines[] = "class {$c['Module']} extends BaseModel";
        $lines[] = '{';
        $lines[] = "    protected \$name = '{$c['table']}';";

        foreach ([...$scope['decl'], ...$scope['extra']] as $line) {
            $lines[] = $line;
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $c */
    private static function tplLogic(array $c): string
    {
        $f = $c['plugin'];
        $M = $c['Module'];

        // 有软删列才生成「回收站」数据源开关，否则退化成普通查询 / 物理删除
        $soft      = self::hasColumn($c, 'delete_time');
        // 插值点位于行首，末尾补一个换行即可让下一条 import 回到行首
        $softUse   = $soft ? "use plugin\\cccms\\support\\SoftDelete;\n" : '';
        $listQuery = $soft
            ? "SoftDelete::scope({$M}::newScopedQuery(), !empty(\$params['trashed']))"
            : "{$M}::newScopedQuery()";
        $delete    = $soft
            ? "SoftDelete::remove({$M}::newScopedQuery(), \$id);"
            : "{$M}::newScopedQuery()->where('{$c['pk']}', \$id)->delete();";
        // 可写字段白名单：排除主键与系统维护列，其余按表结构生成
        $fields    = "['" . implode("', '", self::writableFields($c)) . "']";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace plugin\\{$f}\\app\\logic;

        use plugin\\{$f}\\app\\model\\{$M};
        {$softUse}use plugin\\cccms\\support\\ApiException;
        use plugin\\cccms\\support\\FilterInput;

        /**
         * {$c['title']}逻辑（代码生成器生成，请按需补充业务规则）。
         *
         * 数据权限由 `{$M}` 模型的全局作用域自动注入（见模型里的 `\$dataScope` 声明），
         * 所以这里**不要**再用 `Db::name('{$c['table']}')` —— 那条查询会绕过数据权限，
         * `cccms:data-scope-check` 会直接报错。
         */
        final class {$M}Logic
        {
            /**
             * 可写字段白名单（防 Mass Assignment）。
             *
             * 由代码生成器按表结构生成：**已排除主键与系统维护列**
             * （`tenant_id` / `create_time` / `update_time` / `delete_time` 等），
             * 请按业务需要增删；`cccms:write-guard-check` 会校验写方法是否声明了它。
             */
            private const FIELDS = {$fields};

            public static function paginate(array \$params): array
            {
                \$query = {$listQuery};
                // TODO: 按需补充筛选条件
                \$page  = max(1, (int)(\$params['page'] ?? 1));
                \$limit = max(1, (int)(\$params['limit'] ?? 15));
                \$total = \$query->count();
                \$list  = \$query->page(\$page, \$limit)->order('{$c['pk']}', 'desc')->select()->toArray();

                return ['total' => \$total, 'list' => \$list];
            }

            public static function create(array \$data): int
            {
                \$data = FilterInput::only(\$data, self::FIELDS);
                // 新增的数据还没有归属，插入语句不需要数据权限条件
                return (int){$M}::withoutGlobalScope()->insertGetId(\$data);
            }

            public static function update(int \$id, array \$data): void
            {
                \$data = FilterInput::only(\$data, self::FIELDS);

                self::assertExists(\$id);
                // 作用域随模型自动生效：改范围外的行时不会更新到任何数据
                {$M}::newScopedQuery()->where('{$c['pk']}', \$id)->update(\$data);
            }

            public static function delete(int \$id): void
            {
                self::assertExists(\$id);
                {$delete}
            }

            /** 存在性校验要看全量数据（含范围外的行），显式跳出作用域 */
            private static function assertExists(int \$id): void
            {
                if (!{$M}::withoutGlobalScope()->where('{$c['pk']}', \$id)->find()) {
                    throw new ApiException('{$c['title']}不存在', 404);
                }
            }
        }

        PHP;
    }

    /** @param array<string,mixed> $c */
    private static function tplController(array $c): string
    {
        $f  = $c['plugin'];
        $M  = $c['Module'];
        $ML = $c['ModuleLogic'];
        $slugBase = "{$c['plugin']}:{$c['module']}";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace plugin\\{$f}\\app\\controller;

        use plugin\\{$f}\\app\\logic\\{$ML};
        use plugin\\cccms\\basic\\BaseController;
        use plugin\\cccms\\support\\attribute\\Permission;
        use plugin\\cccms\\support\\attribute\\Restrict;
        use Webman\\Http\\Request;
        use Webman\\Http\\Response;

        /** {$c['title']}（代码生成器生成） */
        class {$M}Controller extends BaseController
        {
            #[Permission(slug: '{$slugBase}:index', title: '{$c['title']}列表')]
            public function index(Request \$request): Response
            {
                return \$this->ok({$ML}::paginate(\$request->get()));
            }

            #[Permission(slug: '{$slugBase}:save', title: '新增{$c['title']}')]
            #[Restrict(methods: ['POST'])]
            public function save(Request \$request): Response
            {
                return \$this->ok(['id' => {$ML}::create(\$request->post())], '创建成功');
            }

            #[Permission(slug: '{$slugBase}:update', title: '更新{$c['title']}')]
            #[Restrict(methods: ['POST'])]
            public function update(Request \$request): Response
            {
                {$ML}::update((int)\$request->input('{$c['pk']}', 0), \$request->post());
                return \$this->ok(null, '更新成功');
            }

            #[Permission(slug: '{$slugBase}:delete', title: '删除{$c['title']}')]
            #[Restrict(methods: ['POST'])]
            public function delete(Request \$request): Response
            {
                {$ML}::delete((int)\$request->input('{$c['pk']}', 0));
                return \$this->ok(null, '删除成功');
            }
        }

        PHP;
    }

    /** @param array<string,mixed> $c */
    private static function tplRoute(array $c): string
    {
        $f = $c['plugin'];
        $M = $c['Module'];
        $path = $c['path'];

        return <<<PHP
        <?php

        // {$c['title']} 模块路由（代码生成器生成）
        use plugin\\{$f}\\app\\controller\\{$M}Controller;
        use Webman\\Route;

        Route::get('{$path}', [{$M}Controller::class, 'index']);
        Route::post('{$path}/save', [{$M}Controller::class, 'save']);
        Route::post('{$path}/update', [{$M}Controller::class, 'update']);
        Route::post('{$path}/delete', [{$M}Controller::class, 'delete']);

        PHP;
    }

    /** @param array<string,mixed> $c */
    private static function tplApi(array $c): string
    {
        $m = $c['module'];
        $path = $c['path'];

        return <<<TS
        import { http } from '@/api/request'
        import type { PageResult } from '@/api/types'

        export function {$m}List(params: Record<string, unknown>) {
          return http.get<PageResult>('{$path}', params)
        }

        export function {$m}Save(data: Record<string, unknown>) {
          return http.post<{ id: number }>('{$path}/save', data)
        }

        export function {$m}Update(data: Record<string, unknown>) {
          return http.post<null>('{$path}/update', data)
        }

        export function {$m}Delete(id: number) {
          return http.post<null>('{$path}/delete', { id })
        }

        TS;
    }

    /** @param array<string,mixed> $c */
    private static function tplVue(array $c): string
    {
        $slugBase = "{$c['plugin']}:{$c['module']}";
        $pk = $c['pk'];

        $columnsJs = '';
        $formItems = '';
        foreach ($c['fields'] as $col) {
            $name = $col['name'];
            $label = $col['comment'] !== '' ? $col['comment'] : $name;
            $columnsJs .= "          <a-table-column title=\"{$label}\" data-index=\"{$name}\" />\n";

            $formItems .= match (true) {
                in_array($col['type'], ['int', 'bigint', 'tinyint', 'smallint', 'mediumint', 'decimal', 'float', 'double'], true)
                    => "        <a-form-item field=\"{$name}\" label=\"{$label}\">\n          <a-input-number v-model=\"form.{$name}\" />\n        </a-form-item>\n",
                in_array($col['type'], ['text', 'mediumtext', 'longtext'], true)
                    => "        <a-form-item field=\"{$name}\" label=\"{$label}\">\n          <a-textarea v-model=\"form.{$name}\" :auto-size=\"{ minRows: 2, maxRows: 5 }\" />\n        </a-form-item>\n",
                $col['type'] === 'datetime' || $col['type'] === 'timestamp'
                    => "        <a-form-item field=\"{$name}\" label=\"{$label}\">\n          <a-date-picker v-model=\"form.{$name}\" show-time value-format=\"YYYY-MM-DD HH:mm:ss\" />\n        </a-form-item>\n",
                default
                => "        <a-form-item field=\"{$name}\" label=\"{$label}\">\n          <a-input v-model=\"form.{$name}\" />\n        </a-form-item>\n",
            };
        }

        return <<<VUE
        <template>
          <div class="page-container">
            <a-card :bordered="false">
              <div class="table-toolbar">
                <a-button v-auth="'{$slugBase}:save'" type="primary" @click="openCreate">
                  <template #icon><icon-plus /></template>
                  新增
                </a-button>
              </div>

              <a-table row-key="{$pk}" :data="list" :loading="loading" :pagination="pagination" @page-change="onPageChange">
                <template #columns>
                  <a-table-column title="ID" data-index="{$pk}" :width="80" />
        {$columnsJs}          <a-table-column title="操作" :width="150">
                    <template #cell="{ record }">
                      <a-space>
                        <a-button v-auth="'{$slugBase}:update'" type="text" size="small" @click="openEdit(record)">编辑</a-button>
                        <a-popconfirm content="确定删除？" @ok="onDelete(record.{$pk})">
                          <a-button v-auth="'{$slugBase}:delete'" type="text" status="danger" size="small">删除</a-button>
                        </a-popconfirm>
                      </a-space>
                    </template>
                  </a-table-column>
                </template>
              </a-table>
            </a-card>

            <a-modal v-model:visible="formVisible" :title="form.{$pk} ? '编辑{$c['title']}' : '新增{$c['title']}'" :width="560" @before-ok="submitForm">
              <a-form ref="formRef" :model="form" layout="vertical">
        {$formItems}      </a-form>
            </a-modal>
          </div>
        </template>

        <script setup lang="ts">
        import { onMounted, reactive, ref } from 'vue'
        import { Message } from '@arco-design/web-vue'
        import { {$c['module']}List, {$c['module']}Save, {$c['module']}Update, {$c['module']}Delete } from '@/api/auto/{$c['plugin']}/{$c['module']}'
        import type { PageResult } from '@/api/types'

        interface Row {
          {$pk}: number
          [key: string]: unknown
        }

        const loading = ref(false)
        const list = ref<Row[]>([])
        const pagination = reactive({ total: 0, current: 1, pageSize: 10, showTotal: true })

        async function loadList(): Promise<void> {
          loading.value = true
          try {
            const res = (await {$c['module']}List({ page: pagination.current, limit: pagination.pageSize })) as PageResult<Row>
            list.value = res.list
            pagination.total = res.total
          } finally {
            loading.value = false
          }
        }

        function onPageChange(page: number): void {
          pagination.current = page
          loadList()
        }

        const formRef = ref()
        const formVisible = ref(false)
        const emptyForm = { {$pk}: 0 }
        const form = reactive<Record<string, any>>({ ...emptyForm })

        function openCreate(): void {
          Object.assign(form, emptyForm)
          formVisible.value = true
        }

        function openEdit(record: Row): void {
          Object.assign(form, emptyForm, record)
          formVisible.value = true
        }

        async function submitForm(): Promise<boolean> {
          const errors = await formRef.value?.validate?.()
          if (errors) {
            return false
          }
          if (form.{$pk}) {
            await {$c['module']}Update({ ...form })
          } else {
            await {$c['module']}Save({ ...form })
          }
          Message.success('保存成功')
          formVisible.value = false
          loadList()
          return true
        }

        async function onDelete(id: number): Promise<void> {
          await {$c['module']}Delete(id)
          Message.success('删除成功')
          loadList()
        }

        onMounted(loadList)
        </script>

        VUE;
    }

    /**
     * 登记菜单（type=2）。已有同名 node 则不覆盖。
     *
     * @param array<string,mixed> $c
     * @return array{slug:string,title:string,path:string,component:string}
     */
    private static function registerMenu(array $c): array
    {
        $exists = Menu::where('node', $c['menuSlug'])->find();

        if (!$exists) {
            // 挂到该插件的目录节点下（若存在）
            $parentId = (int)Menu::where('node', $c['plugin'])->where('type', 1)->value('id');
            if ($parentId > 0) {
                Menu::create([
                    'parent_id' => $parentId,
                    'type'      => 2,
                    'title'     => $c['title'],
                    'path'      => $c['path'],
                    'component' => $c['component'],
                    'icon'      => 'icon-apps',
                    'sort'      => 99,
                    'node'      => $c['menuSlug'],
                    'status'    => 1,
                ]);
            }
        }

        return [
            'slug'      => $c['menuSlug'],
            'title'     => $c['title'],
            'path'      => $c['path'],
            'component' => $c['component'],
        ];
    }

    private static function pascal(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }
}
