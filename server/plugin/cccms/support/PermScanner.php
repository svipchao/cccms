<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\model\Menu;
use plugin\cccms\support\attribute\NoAuth;
use plugin\cccms\support\attribute\NoLogin;
use plugin\cccms\support\attribute\Permission;
use plugin\cccms\support\attribute\Restrict;
use plugin\cccms\support\Scan\PluginFs;
use ReflectionClass;
use ReflectionMethod;

/**
 * 权限注解扫描器：扫描控制器 → 校验 → 同步 sys_menu 按钮节点（type=3）。
 */
final class PermScanner
{
    public const SLUG_PATTERN = '/^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*){2,}$/';

    private string $controllerDir;
    private string $namespace;

    public function __construct(?string $controllerDir = null, ?string $namespace = null)
    {
        $this->controllerDir = $controllerDir ?? base_path() . '/plugin/cccms/app/controller';
        $this->namespace = $namespace ?? 'plugin\cccms\app\controller';
    }

    /**
     * 为每个「CCCMS 业务插件」构造一个扫描器（cccms + 各业务插件）。
     *
     * 判定标准：插件提供 `db/menu.php`（声明式菜单）。这样可排除 `plugin/index` 这类
     * 有意的公开前台插件（其控制器本就不声明鉴权注解，不应参与权限校验）。
     *
     * @return array<int,self>
     */
    public static function pluginScanners(): array
    {
        $scanners = [];
        // 插件目录口径统一由 PluginFs 提供（与 data-scope-check / write-guard-check 共用）
        foreach (PluginFs::pluginDirs() as $pluginDir) {
            $controllerDir = $pluginDir . '/app/controller';
            if (!is_dir($controllerDir)) {
                continue;
            }
            $plugin = basename($pluginDir);
            $scanners[] = new self($controllerDir, 'plugin\\' . $plugin . '\\app\\controller');
        }
        return $scanners;
    }

    /**
     * 扫描所有插件的控制器注解（cccms + 各业务插件）。
     *
     * @return array<int,array>
     */
    public static function scanAllPlugins(): array
    {
        $items = [];
        foreach (self::pluginScanners() as $scanner) {
            foreach ($scanner->scan() as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * 扫描所有 BaseController 子类的方法声明。
     *
     * @return array<int,array{controller:string,action:string,slug:?string,title:?string,sort:int,group:?string,noAuth:bool,noLogin:bool,methods:array,encode:?array}>
     */
    public function scan(): array
    {
        $items = [];
        foreach (ControllerScanner::scan($this->controllerDir, $this->namespace) as $controller) {
            $class = new ReflectionClass($controller);
            $classNoLogin = $class->getAttributes(NoLogin::class) !== [];
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $controller || $method->isConstructor()) {
                    continue;
                }
                $action = $method->getName();
                $meta = PermissionMeta::of($controller, $action);
                $items[] = [
                    'controller' => $controller,
                    'action'     => $action,
                    'slug'       => $meta['slug'],
                    'title'      => $meta['title'],
                    'sort'       => $meta['sort'],
                    'group'      => $meta['group'],
                    'noAuth'     => $meta['noAuth'],
                    'noLogin'    => $meta['noLogin'],
                    'methods'    => $meta['methods'],
                    'encode'     => $meta['encode'],
                    'classNoLogin' => $classNoLogin,
                ];
            }
        }
        return $items;
    }

    /**
     * 纯代码校验（无需数据库、无实例状态，故为静态方法）。
     *
     * @param array $items scan() / scanAllPlugins() 的结果
     * @return array<int,string> 错误信息列表
     */
    public static function validate(array $items): array
    {
        $errors = [];
        $seen = [];

        foreach ($items as $item) {
            $where = "{$item['controller']}::{$item['action']}";

            // 1. fail-closed：无任何鉴权属性
            if (!$item['noAuth'] && !$item['noLogin'] && $item['slug'] === null) {
                $errors[] = "[fail-closed] {$where} 未声明 #[Permission]/#[NoAuth]/#[NoLogin]";
                continue;
            }

            // 2. 类级 NoLogin 内不得有 Permission
            if ($item['classNoLogin'] && $item['slug'] !== null) {
                $errors[] = "[互斥] {$where} 所在类为 #[NoLogin]，方法不得再声明 #[Permission]";
            }

            // 3. slug 格式
            if ($item['slug'] !== null && !preg_match(self::SLUG_PATTERN, $item['slug'])) {
                $errors[] = "[格式] {$where} slug '{$item['slug']}' 非法（需 a:b:c 及以上，全小写）";
            }

            // 4. slug 全局唯一
            if ($item['slug'] !== null) {
                if (isset($seen[$item['slug']])) {
                    $errors[] = "[唯一] slug '{$item['slug']}' 重复：{$seen[$item['slug']]} 与 {$where}";
                }
                $seen[$item['slug']] = $where;
            }

            // 5. Restrict 取值合法
            foreach ($item['methods'] as $m) {
                if (!in_array($m, Restrict::METHODS, true)) {
                    $errors[] = "[Restrict] {$where} methods 含非法值 '{$m}'";
                }
            }
            if ($item['encode'] !== null) {
                foreach ($item['encode'] as $e) {
                    if (!in_array($e, Restrict::ENCODES, true)) {
                        $errors[] = "[Restrict] {$where} encode 含非法值 '{$e}'";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * 同步按钮节点到 sys_menu（只增不删；已存在的仅做「恢复软删」与「纠正归属菜单」，
     * 不覆盖管理员改过的 title / sort）。
     *
     * @param array $items scan() 的结果
     * @return array{created:int,skipped:int}
     */
    public function sync(array $items): array
    {
        $buttons = array_values(array_filter($items, fn ($i) => $i['slug'] !== null));

        // 现有菜单节点（目录/菜单）的 node 列表：只用未删除的，
        // 否则新按钮会被挂到一个躺在回收站里的目录下（挂上了也看不见）
        $menuNodes = Menu::where('type', 'in', [1, 2])->column('node');
        /*
         * 按钮节点**必须含回收站**（withTrashed）。
         *
         * `sys_menu.uk_node` 是**表级**唯一键，已软删的行同样占着那个 node。
         * 不带 withTrashed() 就查不到它们，于是走到下面的 INSERT 直接撞唯一键：
         *   SQLSTATE[23000] 1062 Duplicate entry 'xxx' for key 'sys_menu.uk_node'
         * 而下面那段「恢复软删 + 纠正归属菜单」的逻辑**永远执行不到**。
         *
         * 触发场景很常见：某个功能下线（菜单连同其按钮被一起软删），过段时间又上线，
         * 重跑 perm-scan 就报这个错 —— 命令行报的是 SQL 约束冲突，看不出真实原因。
         */
        $existingNodes = Menu::withTrashed()->where('type', 3)->column('node');

        $created = 0;
        $skipped = 0;

        foreach ($buttons as $item) {
            $parentNode = $this->resolveParent($item['slug'], $item['group'], $menuNodes);
            if ($parentNode === null) {
                throw new ApiException("按钮 '{$item['slug']}' 找不到归属菜单，请补建菜单或使用 group 指定", 500);
            }
            $parentId = (int)Menu::where('node', $parentNode)->value('id');

            if (in_array($item['slug'], $existingNodes, true)) {
                // 代码里仍声明该按钮 → 若它躺在回收站里，说明被重新启用，恢复之（不覆盖 title/sort）。
                // 这两句就是要在**已删行**上操作，必须 withTrashed()：
                // 模型默认排除已删数据，不加的话 whereNotNull(delete_time) 会与默认条件自相矛盾，
                // 更新静默变成 0 行 —— 表现为「菜单同步了但权限还是没恢复」，很难排查。
                Menu::withTrashed()
                    ->where('node', $item['slug'])
                    ->whereNotNull('delete_time')
                    ->update(['delete_time' => null]);
                // 归属菜单以代码为准：菜单节点被挪走或删除后（例如「回收站」并进了各模块页面），
                // 按钮要跟着换父节点。否则它会挂在一个已删除的节点下 —— 行还在，
                // 但在角色授权树里根本看不见，管理员无法勾选，表现为「权限静默失效」。
                Menu::withTrashed()
                    ->where('node', $item['slug'])
                    ->where('parent_id', '<>', $parentId)
                    ->update(['parent_id' => $parentId]);
                $skipped++;
                continue;
            }

            $menu = new Menu();
            $menu->save([
                'parent_id' => $parentId,
                'type'      => 3,
                'title'     => $item['title'],
                'node'      => $item['slug'],
                'sort'      => $item['sort'],
                'status'    => 1,
            ]);
            $created++;
        }

        // 按钮节点即权限节点：同步后所有用户的权限集合都要重算
        PermissionCache::bump();

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * 查找按钮归属菜单 slug：group 优先，否则按最长前缀匹配。
     *
     * @param array<int,string> $menuSlugs
     */
    private function resolveParent(string $slug, ?string $group, array $menuSlugs): ?string
    {
        if ($group !== null && $group !== '' && in_array($group, $menuSlugs, true)) {
            return $group;
        }

        return Slug::longestOwner($slug, $menuSlugs);
    }

    /**
     * 反向检查：sys_menu 中 type=3 且代码已无对应注解的僵尸节点。
     *
     * @param array $items scan() 的结果
     * @return array<int,string>
     */
    public function zombieNodes(array $items): array
    {
        $codeSlugs = [];
        foreach ($items as $item) {
            if ($item['slug'] !== null) {
                $codeSlugs[$item['slug']] = true;
            }
        }
        $zombies = [];
        // 回收站里的节点不算僵尸（它是被主动删掉的）
        foreach (Menu::where('type', 3)->column('node') as $node) {
            if (!isset($codeSlugs[$node])) {
                $zombies[] = $node;
            }
        }
        return $zombies;
    }
}
