# 基础系统 Start
CREATE TABLE `sys_user`
(
    `id`          int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `nickname`    varchar(32)  NOT NULL COMMENT '昵称(chsDash|length:5,32)',
    `username`    varchar(32)  NOT NULL COMMENT '用户名(alphaDash|length:5,32)',
    `password`    char(32)     NOT NULL COMMENT '密码(alphaNum|length:5,32)',
    `token`       char(32)     NOT NULL COMMENT 'Token(alphaNum|length:32)',
    `isadmin`     tinyint      NOT NULL DEFAULT 0 COMMENT '管理员(in:0,1|length:1)【0:禁用,1:启用】',
    `loginip`     varchar(45)  NOT NULL DEFAULT 0 COMMENT '登录IP',
    `logintime`   int          NOT NULL DEFAULT 0 COMMENT '登录时间',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1|length:1)【0:禁用,1:启用】',
    `create_time` int                   DEFAULT 0 COMMENT '创建时间',
    `update_time` int                   DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_username` (`username`),
    INDEX `idx_status` (`status`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户表';
INSERT INTO `sys_user` (`id`, `username`, `password`, `nickname`, `token`, `isadmin`)
VALUES (1, 'admin', '21232f297a57a5a743894a0e4a801fc3', '诗无尽头i', '21232f297a57a5a743894a0e4a801fc3', 1),
       (2, 'admin123', '21232f297a57a5a743894a0e4a801fc3', '开发测试', '21232f297a57a5a743894a0e4a801fc3', 0);

CREATE TABLE `sys_role`
(
    `id`          int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `role_id`     int UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级角色ID(different:id)',
    `role_name`   varchar(32)  NOT NULL DEFAULT 0 COMMENT '角色名称',
    `role_desc`   varchar(255) NOT NULL DEFAULT 0 COMMENT '角色备注',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT 'status(in:0,1|length:1)【0:禁用,1:启用】',
    `create_time` int                   DEFAULT 0 COMMENT '创建时间',
    `update_time` int                   DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_status` (`status`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色表';
INSERT INTO `sys_role` (`id`, `role_id`, `role_name`, `role_desc`)
VALUES (1, 0, '角色1', '角色1'),
       (2, 0, '角色2', '角色2'),
       (3, 1, '角色3', '角色3'),
       (4, 1, '角色4', '角色4'),
       (5, 3, '角色5', '角色5');

# 可能会存在提权问题 暂停此项功能
# CREATE TABLE `sys_role_table_rule`
    # (
          #     `id`      int UNSIGNED NOT NULL AUTO_INCREMENT,
          #     `role_id` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
          #     `table`   varchar(255) NOT NULL DEFAULT 0 COMMENT '表名',
    #     `field`   varchar(255) NOT NULL DEFAULT 0 COMMENT '字段',
    #     `express` varchar(255) NOT NULL DEFAULT 0 COMMENT '表达式',
    #     `cond`    varchar(255) NOT NULL DEFAULT 0 COMMENT '条件',
    #     PRIMARY KEY (`id`) USING BTREE
    # ) ENGINE = InnoDB
    #   DEFAULT CHARSET = utf8mb4
    #   COLLATE = utf8mb4_general_ci COMMENT = '角色数据库表规则';

CREATE TABLE `sys_role_node`
(
    `role_id` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
    `node`    varchar(255) NOT NULL DEFAULT 0 COMMENT '节点',
    UNIQUE KEY `idx_role_node` (`role_id`, `node`),
    KEY `idx_role_id` (`role_id`),
    KEY `idx_node` (`node`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色节点表';

CREATE TABLE `sys_user_role`
(
    `user_id` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
    `role_id` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
    UNIQUE KEY `idx_user_role` (`user_id`, `role_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_role_id` (`role_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户角色表';

CREATE TABLE `sys_group_role`
(
    `group_id` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '组织ID',
    `role_id`  int UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
    UNIQUE KEY `idx_group_role` (`group_id`, `role_id`),
    KEY `idx_group_id` (`group_id`),
    KEY `idx_role_id` (`role_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '组织角色表';

CREATE TABLE `sys_group`
(
    `id`          int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `group_id`    int UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级组织ID(different:id)',
    `group_name`  varchar(32)  NOT NULL DEFAULT 0 COMMENT '组织名称',
    `group_desc`  varchar(255) NOT NULL DEFAULT 0 COMMENT '组织备注',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT 'status(in:0,1|length:1)【0:禁用,1:启用】',
    `create_time` int                   DEFAULT 0 COMMENT '创建时间',
    `update_time` int                   DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_status` (`status`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '组织表';
INSERT INTO `sys_group` (`id`, `group_id`, `group_name`, `group_desc`)
VALUES (1, 0, '组织1', '组织1'),
       (2, 0, '组织2', '组织2'),
       (3, 1, '组织3', '组织3'),
       (4, 1, '组织4', '组织4'),
       (5, 3, '组织5', '组织5');

CREATE TABLE `sys_user_group`
(
    `user_id`  int UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
    `group_id` int UNSIGNED NOT NULL DEFAULT 0 COMMENT '组织ID',
    UNIQUE KEY `idx_user_group` (`user_id`, `group_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_group_id` (`group_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户组织表';

CREATE TABLE `sys_route`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `alias`       varchar(255) NOT NULL COMMENT '别名',
    `url`         varchar(255) NOT NULL COMMENT 'URL',
    `ext`         varchar(10)  NOT NULL DEFAULT 'html' COMMENT '链接后缀',
    `name`        varchar(255) NOT NULL COMMENT '路由标识',
    `desc`        varchar(255) NOT NULL COMMENT '备注',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT 'status(in:0,1|length:1)【0:禁用,1:启用】',
    `create_time` int                   DEFAULT 0 COMMENT '创建时间',
    `update_time` int                   DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '路由表';
INSERT INTO `sys_route` (`id`, `alias`, `url`, `ext`, `name`, `desc`)
VALUES (1, 'test/:id', 'index/test/test', 'html', 'test', '测试页test'),
       (2, 'index', 'index/test/index', 'html', 'index', '测试页index');

CREATE TABLE `sys_menu`
(
    `id`          int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type`        varchar(20)  NOT NULL COMMENT '菜单类别',
    `type_alias`  varchar(20)  NOT NULL DEFAULT '' COMMENT '类别别名',
    `menu_id`     int UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级菜单ID(different:id)',
    `name`        varchar(32)  NOT NULL COMMENT '菜单名称',
    `icon`        varchar(32)  NOT NULL DEFAULT '' COMMENT '菜单图标',
    `url`         varchar(255) NOT NULL DEFAULT '#' COMMENT '链接',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT 'status(in:0,1|length:1)【0:禁用,1:启用】',
    `create_time` int                   DEFAULT 0 COMMENT '创建时间',
    `update_time` int                   DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_status` (`status`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '菜单';
INSERT INTO `sys_menu` (`id`, `type`, `type_alias`, `menu_id`, `name`, `icon`, `url`, `sort`)
VALUES (1, 'Admin', '后台菜单', 0, '控制台', 'layui-icon-home', 'admin/index/index', 900),
       (2, 'Admin', '后台菜单', 0, '权限配置', 'layui-icon-auz', '#', 500),
       (3, 'Admin', '后台菜单', 0, '系统配置', 'layui-icon-set-fill', '#', 400),
       (4, 'Admin', '后台菜单', 2, '组织管理', '', 'admin/group/index', 590),
       (5, 'Admin', '后台菜单', 2, '角色管理', '', 'admin/role/index', 580),
       (6, 'Admin', '后台菜单', 2, '用户管理', '', 'admin/user/index', 570),
       (7, 'Admin', '后台菜单', 3, '配置管理', '', 'admin/config/index', 490),
       (8, 'Admin', '后台菜单', 3, '菜单管理', '', 'admin/menu/index', 480),
       (9, 'Admin', '后台菜单', 3, '路由管理', '', 'admin/route/index', 470),
       (10, 'Admin', '后台菜单', 3, '日志管理', '', 'admin/log/index', 460),
       (11, 'Admin', '后台菜单', 0, '文章系统', 'layui-icon-read', '#', 300),
       (12, 'Admin', '后台菜单', 10, '添加文章', '', 'cms/article/create', 390),
       (13, 'Admin', '后台菜单', 10, '文章管理', '', 'cms/article/index', 380),
       (14, 'Admin', '后台菜单', 10, '栏目管理', '', 'cms/column/index', 370),
       (15, 'Admin', '后台菜单', 10, '标签管理', '', 'cms/tag/index', 360),
       (16, 'Admin', '后台菜单', 10, '评论管理', '', 'cms/comment/index', 350);

DROP TABLE IF EXISTS `sys_log`;
CREATE TABLE `sys_log`
(
    `id`          int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `user_id`     int UNSIGNED NOT NULL COMMENT '用户ID',
    `name`        varchar(32)  NOT NULL COMMENT '行为名称',
    `node`        varchar(255) NOT NULL COMMENT '操作节点',
    `req_param`   text         NOT NULL COMMENT '请求参数',
    `req_ip`      varchar(45)  NOT NULL DEFAULT 0 COMMENT '请求IP',
    `req_method`  varchar(7)   NOT NULL DEFAULT 0 COMMENT '请求类型',
    `req_ua`      varchar(255) NOT NULL DEFAULT 0 COMMENT 'User-Agent',
    `create_time` int                   DEFAULT 0 COMMENT '创建时间',
    `update_time` int                   DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_user_id` (`user_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '日志';

DROP TABLE IF EXISTS `sys_config`;
CREATE TABLE `sys_config`
(
    `id`          int UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type`        varchar(20)   NOT NULL DEFAULT '' COMMENT '类别',
    `type_alias`  varchar(20)   NOT NULL DEFAULT '' COMMENT '类别别名',
    `key`         varchar(100)  NOT NULL DEFAULT '' COMMENT '键名',
    `val`         varchar(1024) NOT NULL DEFAULT '' COMMENT '键值',
    `desc`        varchar(255)  NOT NULL COMMENT '备注',
    `form_type`   varchar(20)   NOT NULL DEFAULT '' COMMENT '表单类型',
    `form_val`    varchar(1024) NOT NULL DEFAULT '' COMMENT '表单预设值',
    `create_time` int                    DEFAULT 0 COMMENT '创建时间',
    `update_time` int                    DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_key` (`key`) USING BTREE,
    INDEX `idx_type` (`type`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '配置';
INSERT INTO `sys_config` (`type`, `type_alias`, `key`, `val`, `desc`, `form_type`, `form_val`)
VALUES ('log', '日志配置', 'logClose', 1, '日志状态', 'switch', '0,1'),
       ('log', '日志配置', 'logMethods', 'put,post,delete', '日志监控的方法', 'checkbox', 'get,post,put,delete,ajax,pjax,json,mobile,head,patch,options,cli,cgi'),
       ('log', '日志配置', 'logParams', 'v,page,limit,field,order,encode', '日志不记录的参数', 'textarea', ''),
       ('site', '网站配置', 'siteName', '诗无尽头i', '网站名称', 'input', '诗无尽头i'),
       ('site', '网站配置', 'siteIcp', '豫ICP备93093369号', '备案号', 'input', '豫ICP备93093369号'),
       ('login', '登录配置', 'loginExpire', 86400, '登录过期时间', 'input', 86400),
       ('login', '登录配置', 'singleLogin', 1, '是否单点登陆', 'switch', '0,1'),
       ('login', '登录配置', 'loginFailure', 10, '尝试登录失败次数', 'input', 10),
       ('login', '登录配置', 'loginFailureTime', 86400, '尝试登录失败禁止时间(秒)', 'input', 86400),
       ('base', '系统配置', 'codeKey', '诗无尽头i', '加解密密钥', 'input', '诗无尽头i'),
       ('base', '系统配置', 'resultPath', '../cccms/view/result.html', '返回视图路径', 'input', 'result.html'),
       ('base', '系统配置', 'middleware', 'think\\middleware\\SessionInit', '全局中间件', 'textarea', ''),
       ('node', '节点设置', 'folderPath', 'app/admin|app/index|app/cms', '需要扫描的文件夹', 'textarea', ''),
       ('node', '节点设置', 'folderAlias', 'admin,后台|index,前台|api,API', '文件夹(应用)别名', 'textarea', ''),
       ('node', '节点设置', 'ignoresFile', 'Error|view|model', '忽略的文件(夹)名 不带后缀', 'textarea', ''),
       ('node', '节点设置', 'ignoresAction', '__call|__construct', '忽略的方法', 'textarea', ''),
       ('session', 'Session', 'expire', 86400, 'Session过期时间', 'input', 86400),
       ('database', '数据库设置', 'auto_timestamp', 'int', '自动写入时间戳类型', 'input', 'int'),
       ('database', '数据库设置', 'datetime_format', 0, '自动转换时间戳格式', 'switch', '0,1');
-- 基础系统 End