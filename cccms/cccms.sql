CREATE TABLE `sys_user`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `nickname`    varchar(32)  NOT NULL DEFAULT '' COMMENT '昵称(chsDash|length:2,32)',
    `username`    varchar(32)  NOT NULL DEFAULT '' COMMENT '用户名(alphaDash|length:5,32|unique:sys_user)',
    `password`    char(32)     NOT NULL DEFAULT '' COMMENT '密码(alphaNum|length:5,32)',
    `avatar`      varchar(50)  NOT NULL DEFAULT '' COMMENT '头像(length:1,50)',
    `intro`       varchar(255) NOT NULL DEFAULT '' COMMENT '简介(length:1,255)',
    `token`       char(32)     NOT NULL DEFAULT '' COMMENT 'Token(alphaNum|length:32)',
    `type`        tinyint      NOT NULL DEFAULT 1 COMMENT '类型(in:0,1|length:1)【0:用户,1:会员】',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1|length:1)【0:禁用,1:正常】',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_username` (`username`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户表';
INSERT INTO `sys_user` (`id`, `nickname`, `username`, `password`, `avatar`, `intro`, `token`, `type`, `status`)
VALUES (1, 'Admin', 'admin', '21232f297a57a5a743894a0e4a801fc3', '', '超级管理员', '21232f297a57a5a743894a0e4a801fc3', 0, 1);

CREATE TABLE `sys_group`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `group_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '父级ID(different:id)',
    `group_name`  varchar(32)  NOT NULL DEFAULT '' COMMENT '组织名称(require)',
    `group_desc`  varchar(255) NOT NULL DEFAULT '' COMMENT '组织备注(noRequire)',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1|length:1)【0:禁用,1:正常】',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '组织表';
INSERT INTO `sys_group` (`id`, `group_id`, `group_name`, `group_desc`)
VALUES (1, 0, '用户组', '普通用户组'),
       (2, 0, '客服一组', '客服一组'),
       (3, 2, '售前组', '客服售前组'),
       (4, 2, '售后组', '客服售后组'),
       (5, 0, '客服二组', '客服二组'),
       (6, 5, '售前组', '客服售前组'),
       (7, 5, '售后组', '客服售后组');

CREATE TABLE `sys_role`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `role_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '父级ID(different:id)',
    `role_name`   varchar(32)  NOT NULL DEFAULT '' COMMENT '角色名称(require)',
    `role_desc`   varchar(255) NOT NULL DEFAULT '' COMMENT '角色备注(noRequire)',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1|length:1)【0:禁用,1:正常】',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色表';
INSERT INTO `sys_role` (`id`, `role_id`, `role_name`, `role_desc`)
VALUES (1, 0, '普通用户', '普通用户权限合集'),
       (2, 1, '客服', '客服权限合集'),
       (3, 2, '售前', '客服售前权限合集'),
       (4, 3, '售后', '客服售后权限合集');

CREATE TABLE `sys_user_group`
(
    `user_id`  int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `group_id` int unsigned NOT NULL DEFAULT 0 COMMENT '组织ID',
    UNIQUE KEY `idx_user_group` (`user_id`, `group_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户组织表';

CREATE TABLE `sys_group_role`
(
    `group_id` int unsigned NOT NULL DEFAULT 0 COMMENT '组织ID',
    `role_id`  int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    UNIQUE KEY `idx_group_role` (`group_id`, `role_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '组织角色表';
INSERT INTO `sys_group_role` (`group_id`, `role_id`)
VALUES (1, 1),
       (2, 2),
       (3, 3),
       (4, 4),
       (5, 2),
       (6, 3),
       (7, 4);

CREATE TABLE `sys_role_node`
(
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    `node`    varchar(100) NOT NULL DEFAULT '' COMMENT '节点',
    UNIQUE KEY `idx_role_node` (`role_id`, `node`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色权限表';

CREATE TABLE `sys_data`
(
    `id`      int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    `table`   varchar(255) NOT NULL DEFAULT '' COMMENT '表名',
    `field`   varchar(255) NOT NULL DEFAULT '' COMMENT '字段名',
    `where`   varchar(255) NOT NULL DEFAULT '' COMMENT '条件',
    `value`   varchar(255) NOT NULL DEFAULT '' COMMENT '值',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_role_id` (`role_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '数据权限表';

CREATE TABLE `sys_log`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `user_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `name`        varchar(32)  NOT NULL DEFAULT '' COMMENT '行为名称',
    `node`        varchar(255) NOT NULL DEFAULT '' COMMENT '操作节点',
    `req_param`   text         NOT NULL COMMENT '请求参数',
    `req_ip`      varchar(45)  NOT NULL DEFAULT '' COMMENT '请求IP',
    `req_method`  varchar(7)   NOT NULL DEFAULT '' COMMENT '请求类型',
    `req_ua`      varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_user_id` (`user_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '日志表';

CREATE TABLE `sys_types`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type`        int unsigned NOT NULL DEFAULT 0 COMMENT '分类(between:1,4|length:1)',
    `name`        varchar(32)  NOT NULL DEFAULT '' COMMENT '类别名称',
    `alias`       varchar(32)  NOT NULL DEFAULT '' COMMENT '类别别名',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '基础类别表';
INSERT INTO `sys_types` (`id`, `type`, `name`, `alias`, `sort`)
VALUES (100, 1, '基础系统', 'base', 999),
       (101, 1, 'CMS系统', 'cms', 999),
       (200, 2, '网站配置', 'site', 999),
       (201, 2, '日志配置', 'log', 998),
       (202, 2, '存储配置', 'storage', 997),
       (300, 3, '默认路由', 'route', 993),
       (400, 4, '默认附件', 'file', 993);

CREATE TABLE `sys_route`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '路由类别ID',
    `alias`       varchar(255) NOT NULL DEFAULT '' COMMENT '别名',
    `url`         varchar(255) NOT NULL DEFAULT '' COMMENT 'URL',
    `ext`         varchar(10)  NOT NULL DEFAULT 'html' COMMENT '链接后缀',
    `name`        varchar(255) NOT NULL DEFAULT '' COMMENT '路由标识',
    `desc`        varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1|length:1)【0:禁用,1:正常】',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_type_id` (`type_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '路由表';
INSERT INTO `sys_route` (`id`, `type_id`, `alias`, `url`, `ext`, `name`, `desc`)
VALUES (1, 300, 'index', 'index/index/index', 'html', 'index', '首页');

CREATE TABLE `sys_menu`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '菜单类别ID',
    `menu_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '父级菜单ID(different:id)',
    `name`        varchar(32)  NOT NULL DEFAULT '' COMMENT '菜单名称',
    `icon`        varchar(32)  NOT NULL DEFAULT '' COMMENT '菜单图标(noRequire)',
    `url`         varchar(255) NOT NULL DEFAULT '#' COMMENT '链接',
    `node`        varchar(255) NOT NULL DEFAULT '#' COMMENT '节点',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1|length:1)【0:禁用,1:正常】',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_type_id` (`type_id`) USING BTREE,
    INDEX `idx_menu_id` (`menu_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '菜单表';
INSERT INTO `sys_menu` (`id`, `type_id`, `menu_id`, `name`, `icon`, `url`, `node`, `sort`, `status`)
VALUES (1, 100, 0, '权限配置', 'icon-safetycertificate', '#', '#', 500, 1),
       (2, 100, 1, '角色管理', '', 'admin/role/index', 'admin/role/index', 590, 1),
       (3, 100, 1, '数据权限', '', 'admin/data/index', 'admin/data/index', 580, 1),
       (4, 100, 1, '组织管理', '', 'admin/group/index', 'admin/group/index', 570, 1),
       (5, 100, 1, '用户管理', '', 'admin/user/index', 'admin/user/index', 560, 1),
       (6, 100, 0, '系统配置', 'icon-setting', '#', '#', 400, 1),
       (7, 100, 6, '公共类别', '', 'admin/types/index', 'admin/types/index', 490, 1),
       (8, 100, 6, '菜单管理', '', 'admin/menu/index', 'admin/menu/index', 480, 1),
       (9, 100, 6, '配置管理', '', 'admin/config/index', 'admin/config/index', 470, 1),
       (10, 100, 6, '路由管理', '', 'admin/route/index', 'admin/route/index', 460, 1),
       (11, 100, 6, '附件管理', '', 'admin/file/index', 'admin/file/index', 450, 1),
       (12, 100, 6, '日志管理', '', 'admin/log/index', 'admin/log/index', 440, 1);

CREATE TABLE `sys_file`
(
    `id`           int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type_id`      int unsigned NOT NULL DEFAULT 0 COMMENT '附件类别ID',
    `user_id`      int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `file_size`    int unsigned NOT NULL DEFAULT 0 COMMENT '文件大小',
    `file_url`     varchar(255) NOT NULL DEFAULT '' COMMENT '文件路径',
    `file_name`    varchar(255) NOT NULL DEFAULT '' COMMENT '文件名',
    `file_desc`    varchar(255) NOT NULL DEFAULT '' COMMENT '文件描述',
    `file_ext`     varchar(20)  NOT NULL DEFAULT '' COMMENT '文件后缀',
    `file_mime`    varchar(100) NOT NULL DEFAULT '' COMMENT '文件类型',
    `file_code`    char(32)     NOT NULL DEFAULT '' COMMENT '文件唯一码',
    `extract_code` varchar(20)  NOT NULL DEFAULT '' COMMENT '提取码',
    `status`       tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1|length:1)【0:禁用,1:正常】',
    `create_time`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_file_code` (`file_code`),
    INDEX `idx_type_id` (`type_id`) USING BTREE,
    INDEX `idx_user_id` (`user_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '附件表';

CREATE TABLE `sys_config`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `type_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '配置类别ID',
    `name`        varchar(100)  NOT NULL DEFAULT '' COMMENT '名称',
    `value`       varchar(1024) NOT NULL DEFAULT '' COMMENT '值(noRequire)',
    `configure`   text          NOT NULL COMMENT '配置(noRequire)',
    `create_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_type_id` (`type_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '配置表';
INSERT INTO `sys_config` (`type_id`, `name`, `value`, `configure`)
VALUES (200, 'siteName', '诗无尽头i', '{"name":"siteName","label":"网站名称","type":"input","placeholder":"请输入网站名称","default":"默认站点","description":"请输入网站名称"}'),
       (200, 'siteIcp', '豫ICP备93093369号', '{"name":"siteIcp","label":"备案号","type":"input","placeholder":"请输入网站备案号","default":"豫ICP备93093369号","description":"请输入网站备案号"}'),
       (201, 'logClose', 1, '{"name":"logClose","label":"日志状态","type":"switch","default":1,"description":"日志状态","options":{"checked":1,"unchecked":0}}'),
       (201, 'logMethods', 'put,post,delete', '{"name":"logMethods","label":"监控类型","type":"multiple-select","placeholder":"请选择需要监控的类型","default":["POST","PUT","DELETE"],"description":"参考：https://www.runoob.com/http/http-methods.html","options":[{"value":"GET","label":"GET"},{"value":"POST","label":"POST"},{"value":"PUT","label":"PUT"},{"value":"DELETE","label":"DELETE"},{"value":"HEAD","label":"HEAD"},{"value":"CONNECT","label":"CONNECT"},{"value":"OPTIONS","label":"OPTIONS"},{"value":"TRACE","label":"TRACE"},{"value":"PATCH","label":"PATCH"}]}'),
       (201, 'logNoParams', 'v,page,limit,field,order,encode', '{"name":"logNoParams","label":"不记录的参数","type":"textarea","placeholder":"请输入不需要记录的参数名","default":"page,limit","description":"每个参数使用英文逗号隔开，例如：page,limit"}'),
       (202, 'uploadExt', 'jpg,png,gif', '{"name":"uploadExt","label":"上传文件类型","type":"textarea","placeholder":"请输入上传文件支持的后缀名","default":"page,limit","description":"每个后缀名使用英文逗号隔开，例如：jpg,png,gif"}'),
       (202, 'uploadSize', 20, '{"name":"uploadSize","label":"上传文件大小限制","type":"input-number","placeholder":"请输入上传文件大小限制","default":20,"description":"请输入上传文件限制(MB)"}'),
       (202, 'diskType', 'local', '{"name":"diskType","label":"磁盘类型","type":"select","placeholder":"请选择磁盘类型","default":"local","description":"请选择磁盘类型","options":[{"value":"local","label":"本地存储"},{"value":"alioss","label":"阿里云"},{"value":"qiniu","label":"七牛云"},{"value":"txoss","label":"腾讯云"},{"value":"uposs","label":"又拍云"}]}');
