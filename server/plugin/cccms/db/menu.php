<?php

/**
 * 菜单 / 目录声明（type=1 目录、type=2 菜单）。
 * 按钮（type=3）由控制器 #[Permission] 注解经 perm-scan 自动生成，此处不声明。
 *
 * 命名约定：{插件}:{模块}:{动作}
 *   菜单 slug   = 插件名:模块     →  cccms:user
 *   按钮 slug   = 插件名:模块:动作 →  cccms:user:index
 *   前端页面    = pages/插件/模块/index.vue
 *   后端控制器  = plugin/插件/app/controller/{模块}Controller.php
 *
 * 目录 slug 说明：为了支持多级分类，目录用 `插件名:分组名` 形式
 * （cccms:perm / cccms:setting）。
 * 分组名刻意不与任何模块名重复，避免 perm-scan 按「最长 slug 前缀」
 * 挂载按钮时把 cccms:xxx:action 误挂到目录上。
 *
 * 调整分类：把下面 items 里的某项整体挪到另一个目录的 children 即可，
 * 然后执行 `php webman cccms:menu-sync`。
 *
 * 删除节点：补一条 ['slug' => 'xxx', 'remove' => true]，menu-sync 会连带删除
 * 该节点（含其子节点）与对应的角色授权；确认各环境都同步过一次后即可删掉该行。
 */
return [
    // ------------------------------------------------------------------
    // 工作台（与「控制台」本就是同一个页面，故不再套一层目录，直接作为一级菜单）
    // ------------------------------------------------------------------
    [
        // 静态路由（/dashboard），这里只做菜单入口：
        // component 留空 → 不注册动态路由，点击后由静态路由承载。
        'slug'      => 'cccms:dashboard',
        'title'     => '工作台',
        'type'      => 2,
        'icon'      => 'icon-home',
        'path'      => '/dashboard',
        'component' => '',
        'sort'      => 1,
    ],

    // ------------------------------------------------------------------
    // 权限配置（账号 / 权限 / 组织）
    // ------------------------------------------------------------------
    [
        'slug'      => 'cccms:perm',
        'title'     => '权限配置',
        'type'      => 1,
        'icon'      => 'icon-safe',
        'path'      => '/cccms/perm',
        'component' => '',
        'sort'      => 2,
        'children'  => [
            // 租户管理是**平台级**动作：接口仅对「处于平台租户的超管」开放
            // （见 TenantLogic::assertPlatformAdmin），授权时不要下发给租户内角色
            ['slug' => 'cccms:tenant', 'title' => '租户管理', 'type' => 2, 'path' => '/cccms/tenant', 'component' => 'cccms/tenant/index', 'icon' => 'icon-management',  'sort' => 1],
            ['slug' => 'cccms:user', 'title' => '用户管理', 'type' => 2, 'path' => '/cccms/user', 'component' => 'cccms/user/index', 'icon' => 'icon-user',  'sort' => 2],
            ['slug' => 'cccms:role', 'title' => '角色管理', 'type' => 2, 'path' => '/cccms/role', 'component' => 'cccms/role/index', 'icon' => 'icon-safe',  'sort' => 3],
            ['slug' => 'cccms:dept', 'title' => '部门管理', 'type' => 2, 'path' => '/cccms/dept', 'component' => 'cccms/dept/index', 'icon' => 'icon-tree',  'sort' => 4],
            ['slug' => 'cccms:post', 'title' => '岗位管理', 'type' => 2, 'path' => '/cccms/post', 'component' => 'cccms/post/index', 'icon' => 'icon-badge', 'sort' => 5],
            ['slug' => 'cccms:data_rule', 'title' => '数据权限', 'type' => 2, 'path' => '/cccms/data_rule', 'component' => 'cccms/data_rule/index', 'icon' => 'icon-key', 'sort' => 6],
            ['slug' => 'cccms:menu', 'title' => '菜单管理', 'type' => 2, 'path' => '/cccms/menu', 'component' => 'cccms/menu/index', 'icon' => 'icon-menu',  'sort' => 7],
        ],
    ],

    // ------------------------------------------------------------------
    // 系统设置（参数 / 字典 / 日志 / 附件 / 任务 / 生成器）
    // ------------------------------------------------------------------
    [
        'slug'      => 'cccms:setting',
        'title'     => '系统设置',
        'type'      => 1,
        'icon'      => 'icon-settings',
        'path'      => '/cccms/setting',
        'component' => '',
        'sort'      => 3,
        'children'  => [
            ['slug' => 'cccms:notice',    'title' => '通知公告', 'type' => 2, 'path' => '/cccms/notice',    'component' => 'cccms/notice/index',    'icon' => 'icon-tickets',  'sort' => 1],
            ['slug' => 'cccms:config',    'title' => '配置管理', 'type' => 2, 'path' => '/cccms/config',    'component' => 'cccms/config/index',    'icon' => 'icon-tool',   'sort' => 2],
            ['slug' => 'cccms:file',      'title' => '附件管理', 'type' => 2, 'path' => '/cccms/file',      'component' => 'cccms/file/index',      'icon' => 'icon-upload', 'sort' => 3],
            ['slug' => 'cccms:dict',      'title' => '字典管理', 'type' => 2, 'path' => '/cccms/dict',      'component' => 'cccms/dict/index',      'icon' => 'icon-book',   'sort' => 4],
            ['slug' => 'cccms:online',    'title' => '在线用户', 'type' => 2, 'path' => '/cccms/online',    'component' => 'cccms/online/index',    'icon' => 'icon-monitor',  'sort' => 5],
            ['slug' => 'cccms:crontab',   'title' => '定时任务', 'type' => 2, 'path' => '/cccms/crontab',   'component' => 'cccms/crontab/index',   'icon' => 'icon-clock',  'sort' => 6],
            ['slug' => 'cccms:generator', 'title' => '代码生成', 'type' => 2, 'path' => '/cccms/generator', 'component' => 'cccms/generator/index', 'icon' => 'icon-code',   'sort' => 7],
            ['slug' => 'cccms:upgrade',   'title' => '在线升级', 'type' => 2, 'path' => '/cccms/upgrade',   'component' => 'cccms/upgrade/index',   'icon' => 'icon-refresh',  'sort' => 8],
            ['slug' => 'cccms:log',       'title' => '操作日志', 'type' => 2, 'path' => '/cccms/log',       'component' => 'cccms/log/index',       'icon' => 'icon-file',   'sort' => 9],
            // 接口监控与接口文档都属于「运维视角」：前者看运行时，后者看契约
            ['slug' => 'cccms:monitor',   'title' => '接口监控', 'type' => 2, 'path' => '/cccms/monitor',   'component' => 'cccms/monitor/index',   'icon' => 'icon-monitor', 'sort' => 10],
            // 接口文档**仅本地开发环境可见**：`MenuLogic::DEV_ONLY_PREFIXES` 在非开发布局下
            // 把它和它的按钮节点一并从 userTree 里过滤掉（线上不注册前端路由）。节点本身仍同步进库，
            // 菜单管理里看得到，便于排查。
            ['slug' => 'cccms:apidoc',    'title' => '接口文档', 'type' => 2, 'path' => '/cccms/apidoc',    'component' => 'cccms/apidoc/index',    'icon' => 'icon-book',   'sort' => 11],
            // 备份是**破坏性**的运维动作（还原会覆盖整库），授权时只给平台超管
            ['slug' => 'cccms:backup',    'title' => '数据备份', 'type' => 2, 'path' => '/cccms/backup',  'component' => 'cccms/backup/index',    'icon' => 'icon-safe',   'sort' => 12],
            // 在线终端只执行 config/terminal.php 里登记的命令，**不提供自由 shell**
            ['slug' => 'cccms:terminal',  'title' => '在线终端', 'type' => 2, 'path' => '/cccms/terminal', 'component' => 'cccms/terminal/index', 'icon' => 'icon-code',   'sort' => 13],
        ],
    ],
];
