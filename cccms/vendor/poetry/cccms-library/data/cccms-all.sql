# ====================================================================================================
# 权限组 用户组 角色组待完善
# ====================================================================================================

CREATE TABLE `sys_user`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `nickname`    varchar(32)  NOT NULL DEFAULT '' COMMENT '昵称',
    `username`    varchar(32)  NOT NULL DEFAULT '' COMMENT '用户名',
    `password`    varchar(32)  NOT NULL DEFAULT '' COMMENT '密码',
    `phone`       varchar(11)  NOT NULL DEFAULT '' COMMENT '手机号',
    `avatar`      varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_username` (`username`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户表';
INSERT INTO `sys_user` (`id`, `nickname`, `username`, `password`, `status`)
VALUES (1, '超级管理员', 'admin', '21232f297a57a5a743894a0e4a801fc3', 1),
       (2, '测试用户', 'admin888', '7fef6171469e80d32c0559f88b377245', 1),
       (3, '用户测试数据3', 'admin3', '7fef6171469e80d32c0559f88b377245', 1);

CREATE TABLE `sys_user_dept_post`
(
    `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `dept_id` int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
    `post_id` int unsigned NOT NULL DEFAULT 0 COMMENT '岗位ID',
    INDEX `idx_dept_id` (`dept_id`) USING BTREE,
    INDEX `idx_post_id` (`post_id`) USING BTREE,
    UNIQUE INDEX `uk_user_dept_post` (`user_id`, `dept_id`, `post_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户部门岗位表';

CREATE TABLE `sys_user_role`
(
    `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    INDEX `idx_role_id` (`role_id`) USING BTREE,
    UNIQUE INDEX `uk_user_role` (`user_id`, `role_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户角色表';

CREATE TABLE `sys_user_node`
(
    `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `node_id` int unsigned NOT NULL DEFAULT 0 COMMENT '权限ID',
    UNIQUE INDEX `uk_user_node` (`user_id`, `node_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户权限表';

CREATE TABLE `sys_dept`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `dept_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '父级ID',
    `dept_ids`    varchar(2048) NOT NULL DEFAULT '' COMMENT '父级ID集合',
    `dept_name`   varchar(32)   NOT NULL DEFAULT '' COMMENT '部门名称',
    `dept_desc`   varchar(255)  NOT NULL DEFAULT '' COMMENT '部门备注',
    `status`      tinyint       NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
    `create_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门表';
INSERT INTO `sys_dept` (`id`, `dept_id`, `dept_ids`, `dept_name`, `dept_desc`, `status`)
VALUES (1, 0, '1', '客服', '客服', 1);

CREATE TABLE `sys_dept_role`
(
    `dept_id` int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    INDEX `idx_role_id` (`role_id`) USING BTREE,
    UNIQUE INDEX `uk_dept_role` (`dept_id`, `role_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门角色表';

CREATE TABLE `sys_dept_post`
(
    `dept_id` int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
    `post_id` int unsigned NOT NULL DEFAULT 0 COMMENT '岗位ID',
    INDEX `idx_post_id` (`post_id`) USING BTREE,
    UNIQUE INDEX `uk_dept_post` (`dept_id`, `post_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门岗位表';

CREATE TABLE `sys_dept_node`
(
    `dept_id` int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
    `node_id` int unsigned NOT NULL DEFAULT 0 COMMENT '权限ID',
    UNIQUE INDEX `uk_dept_node` (`dept_id`, `node_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门权限表';

CREATE TABLE `sys_post`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `post_name`   varchar(32)  NOT NULL DEFAULT '' COMMENT '岗位名称',
    `post_desc`   varchar(255) NOT NULL DEFAULT '' COMMENT '岗位备注',
    `post_range`  tinyint(4)   NOT NULL DEFAULT 0 COMMENT '权限范围【0:本人,1:本人及下属,2:本部门,3:本部门及下属部门】',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '岗位表';

CREATE TABLE `sys_post_role`
(
    `post_id` int unsigned NOT NULL DEFAULT 0 COMMENT '岗位ID',
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    INDEX `idx_post_id` (`post_id`) USING BTREE,
    UNIQUE INDEX `uk_post_role` (`post_id`, `role_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '岗位角色表';

CREATE TABLE `sys_post_node`
(
    `post_id` int unsigned NOT NULL DEFAULT 0 COMMENT '岗位ID',
    `node_id` int unsigned NOT NULL DEFAULT 0 COMMENT '权限ID',
    UNIQUE INDEX `uk_post_node` (`post_id`, `node_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '岗位权限表';

CREATE TABLE `sys_role`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `role_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '父级ID',
    `role_ids`    varchar(2048) NOT NULL DEFAULT '' COMMENT '父级ID集合',
    `role_name`   varchar(32)   NOT NULL DEFAULT '' COMMENT '角色名称',
    `role_desc`   varchar(255)  NOT NULL DEFAULT '' COMMENT '角色备注',
    `status`      tinyint       NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
    `create_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色表';
INSERT INTO `sys_role` (`id`, `role_id`, `role_ids`, `role_name`, `role_desc`, `status`)
VALUES (1, 0, '1', '客服', '客服', 1);

CREATE TABLE `sys_role_node`
(
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    `node_id` int unsigned NOT NULL DEFAULT 0 COMMENT '权限ID',
    UNIQUE INDEX `uk_role_node` (`role_id`, `node_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色权限表';

CREATE TABLE `sys_group`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `group_name`  varchar(32)  NOT NULL DEFAULT '' COMMENT '组织名称',
    `group_desc`  varchar(255) NOT NULL DEFAULT '' COMMENT '组织备注',
    `type`        varchar(16)  NOT NULL DEFAULT '' COMMENT '类型【user,dept,post,role,node】',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '组织表';

CREATE TABLE `sys_group_collect`
(
    `group_id`   int unsigned NOT NULL DEFAULT 0 COMMENT '组织ID',
    `element_id` int unsigned NOT NULL DEFAULT 0 COMMENT '元素ID',
    `type`       varchar(16)  NOT NULL DEFAULT '' COMMENT '类型【user,dept,post,role,node】',
    UNIQUE INDEX `uk_role_node` (`group_id`, `element_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '组织集合表';

CREATE TABLE `sys_mutex`
(
    `mutex_1` int unsigned NOT NULL DEFAULT 0 COMMENT '互斥1',
    `mutex_2` int unsigned NOT NULL DEFAULT 0 COMMENT '互斥2',
    `type`    varchar(16)  NOT NULL DEFAULT '' COMMENT '类型【user,dept,post,role,node】',
    INDEX `idx_mutex_2` (`mutex_2`) USING BTREE,
    INDEX `idx_type` (`type`) USING BTREE,
    UNIQUE INDEX `uk_mutex_1_2_type` (`mutex_1`, `mutex_2`, `type`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '互斥表';

# ====================================================================================================
# ====================================================================================================
# ====================================================================================================

# 默认(字符串) 时间 时间戳 数字 IP Bool
CREATE TABLE `sys_auth`
(
    `user_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `role_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    `dept_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
    `node`       varchar(255) NOT NULL DEFAULT '' COMMENT '权限节点',
    `table_name` varchar(255) NOT NULL DEFAULT '' COMMENT '表名',
    `field`      varchar(255) NOT NULL DEFAULT '' COMMENT '字段名',
    `logical`    tinyint      NOT NULL DEFAULT 0 COMMENT '逻辑运算【1:并且,2:或者,3:允许,4:拒绝】',
    `where`      varchar(255) NOT NULL DEFAULT '' COMMENT '条件',
    `value`      varchar(255) NOT NULL DEFAULT '' COMMENT '值',
    INDEX `idx_user_id` (`user_id`) USING BTREE,
    INDEX `idx_role_id` (`role_id`) USING BTREE,
    INDEX `idx_dept_id` (`dept_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '权限表';
INSERT INTO `sys_auth` (`user_id`, `role_id`, `dept_id`, `node`, `table_name`, `field`, `logical`, `where`, `value`)
VALUES (2, 1, 0, '', '', '', 0, '', ''),
       (2, 0, 1, '', '', '', 0, '', ''),
       (2, 0, 2, '', '', '', 0, '', ''),
       (0, 0, 1, 'admin3', '', '', 0, '', ''),
       (0, 1, 0, 'admin4', '', '', 0, '', ''),
       (0, 0, 2, 'admin5', '', '', 0, '', ''),
       (2, 0, 0, 'admin7', '', '', 0, '', '');

CREATE TABLE `sys_dict_cate`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `dict_name`   varchar(32)  NOT NULL DEFAULT '' COMMENT '字典标识',
    `dict_desc`   varchar(255) NOT NULL DEFAULT '' COMMENT '字典描述',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '数据字典表';

CREATE TABLE `sys_dict`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `dict_name`   varchar(32)  NOT NULL DEFAULT '' COMMENT '字典标识',
    `name`        varchar(255) NOT NULL DEFAULT '' COMMENT '名称',
    `desc`        varchar(255) NOT NULL DEFAULT '' COMMENT '描述',
    `value`       varchar(255) NOT NULL DEFAULT '' COMMENT '字典值',
    `sort`        int unsigned NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `delete_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_dict_name` (`dict_name`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '数据字典详情表';

CREATE TABLE `sys_menu`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `parent_id`   int unsigned NOT NULL DEFAULT 0 COMMENT '顶级ID',
    `menu_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '父级ID',
    `name`        varchar(32)  NOT NULL DEFAULT '' COMMENT '菜单名称',
    `icon`        varchar(32)  NOT NULL DEFAULT '' COMMENT '菜单图标',
    `url`         varchar(255) NOT NULL DEFAULT '#' COMMENT '链接',
    `node`        varchar(255) NOT NULL DEFAULT '#' COMMENT '节点',
    `layout_name` varchar(32)  NOT NULL DEFAULT 'layouts' COMMENT '菜单布局名称',
    `target`      varchar(32)  NOT NULL DEFAULT '_self' COMMENT '链接打开方式',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_menu_id` (`menu_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '菜单表';
INSERT INTO `sys_menu` (`id`, `parent_id`, `menu_id`, `name`, `icon`, `url`, `node`, `layout_name`, `target`, `sort`, `status`)
VALUES (1, 0, 0, '基础系统', 'ri-stack-line', '#', '#', 'layouts', '_self', 0, 1),
       (2, 1, 1, '控制台', 'ri-home-line', 'admin/index/index', 'admin/index/index', 'layouts', '_self', 0, 1),
       (3, 1, 1, '权限配置', 'ri-shield-check-line', '#', '#', 'layouts', '_self', 0, 1),
       (4, 1, 3, '部门管理', '', 'admin/dept/index', 'admin/dept/index', 'layouts', '_self', 0, 1),
       (5, 1, 3, '角色管理', '', 'admin/role/index', 'admin/role/index', 'layouts', '_self', 0, 1),
       (6, 1, 3, '用户管理', '', 'admin/user/index', 'admin/user/index', 'layouts', '_self', 0, 1),
       (7, 1, 1, '系统配置', 'ri-settings-line', '#', '#', 'layouts', '_self', 0, 1),
       (8, 1, 7, '系统设置', '', 'admin/config/index', 'admin/config/index', 'layouts', '_self', 0, 1),
       (9, 1, 7, '菜单管理', '', 'admin/menu/index', 'admin/menu/index', 'layouts', '_self', 0, 1),
       (10, 1, 7, '路由管理', '', 'admin/route/index', 'admin/route/index', 'layouts', '_self', 0, 1),
       (11, 1, 7, '附件管理', '', 'admin/file/index', 'admin/file/index', 'layouts', '_self', 0, 1),
       (12, 1, 7, '日志管理', '', 'admin/log/index', 'admin/log/index', 'layouts', '_self', 0, 1);

CREATE TABLE `sys_config_cate`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `config_name` varchar(32)  NOT NULL DEFAULT '' COMMENT '配置标识',
    `config_desc` varchar(255) NOT NULL DEFAULT '' COMMENT '配置描述',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '配置表';
INSERT INTO `sys_config_cate` (`id`, `config_name`, `config_desc`)
VALUES (1, 'site', '网站配置'),
       (2, 'captcha', '验证码配置'),
       (3, 'log', '日志配置'),
       (4, 'storage', '上传配置'),
       (5, 'watermark', '水印配置');

CREATE TABLE `sys_config`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `config_name` varchar(32)   NOT NULL DEFAULT '' COMMENT '配置标识',
    `name`        varchar(100)  NOT NULL DEFAULT '' COMMENT '名称',
    `label`       varchar(100)  NOT NULL DEFAULT '' COMMENT '标签',
    `value`       varchar(1024) NOT NULL DEFAULT '' COMMENT '值',
    `configure`   text          NOT NULL COMMENT '配置',
    `sort`        int           NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '配置详情表';
INSERT INTO `sys_config` (`id`, `config_name`, `name`, `label`, `value`, `configure`)
VALUES (1, 'site', 'siteName', '网站名称', '诗无尽头i', '{"type":"input","placeholder":"请输入网站名称","default":"默认站点","description":"请输入网站名称"}'),
       (2, 'site', 'siteIcp', '备案号', '豫ICP备93093369号', '{"type":"input","placeholder":"请输入网站备案号","default":"豫ICP备93093369号","description":"请输入网站备案号"}'),
       (3, 'captcha', 'open', '启用验证码', 1, '{"type":"switch","default":1,"description":"是否启用验证码","options":{"checked":1,"unchecked":0}}'),
       (4, 'captcha', 'math', '验证码类型', 0, '{"type":"select","placeholder":"请选择验证码类型","default":0,"description":"请选择验证码类型","options":[{"value":"0","label":"普通验证码"},{"value":"1","label":"算数验证码"}]}'),
       (5, 'captcha', 'length', '验证码位数', 4, '{"type":"input-number","placeholder":"请输入验证码位数，建议4位或5位","default":4,"description":"请输入验证码位数，建议4位或5位"}'),
       (6, 'captcha', 'fontSize', '验证码字体大小', 22, '{"type":"input-number","placeholder":"请输入验证码字体大小(px)","default":22,"description":"请输入验证码字体大小(px)"}'),
       (7, 'captcha', 'matchCase', '是否区分大小写', 0, '{"type":"switch","default":0,"description":"是否区分大小写","options":{"checked":1,"unchecked":0}}'),
       (8, 'captcha', 'useCurve', '混淆曲线', 1, '{"type":"switch","default":1,"description":"是否添加混淆曲线","options":{"checked":1,"unchecked":0}}'),
       (9, 'captcha', 'useNoise', '杂点', 1, '{"type":"switch","default":1,"description":"是否添加杂点","options":{"checked":1,"unchecked":0}}'),
       (10, 'log', 'logClose', '日志状态', 1, '{"type":"switch","default":1,"description":"日志状态","options":{"checked":1,"unchecked":0}}'),
       (11, 'log', 'logMethods', '监控类型', 'put,post,delete', '{"type":"multiple-select","placeholder":"请选择需要监控的类型","default":["POST","PUT","DELETE"],"description":"参考：https://www.runoob.com/http/http-methods.html","options":[{"value":"GET","label":"GET"},{"value":"POST","label":"POST"},{"value":"PUT","label":"PUT"},{"value":"DELETE","label":"DELETE"},{"value":"HEAD","label":"HEAD"},{"value":"CONNECT","label":"CONNECT"},{"value":"OPTIONS","label":"OPTIONS"},{"value":"TRACE","label":"TRACE"},{"value":"PATCH","label":"PATCH"}]}'),
       (12, 'log', 'logNoParams', '不记录的参数', 'v,page,limit,field,order,encode', '{"type":"textarea","placeholder":"请输入不需要记录的参数名","default":"page,limit","description":"每个参数使用英文逗号隔开，例如：page,limit"}'),
       (13, 'storage', 'diskType', '磁盘类型', 'local', '{"type":"select","placeholder":"请选择磁盘类型","default":"local","description":"请选择磁盘类型","options":[{"value":"local","label":"本地存储"},{"value":"alioss","label":"阿里云"},{"value":"qiniu","label":"七牛云"},{"value":"txoss","label":"腾讯云"},{"value":"uposs","label":"又拍云"}]}'),
       (14, 'storage', 'uploadExt', '上传文件类型', 'jpg,png,gif,mp4,mp3', '{"type":"textarea","placeholder":"请输入上传文件支持的后缀名","default":"page,limit","description":"每个后缀名使用英文逗号隔开，例如：jpg,png,gif"}'),
       (15, 'storage', 'uploadSize', '上传文件大小限制', 20, '{"type":"input-number","placeholder":"请输入上传文件大小限制","default":20,"description":"请输入上传文件限制(MB)"}'),
       (16, 'watermark', 'open', '是否启用水印', 1, '{"type":"switch","placeholder":"是否启用水印","options":{"checked":1,"unchecked":0}}');

CREATE TABLE `sys_route_cate`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `route_name`  varchar(32)  NOT NULL DEFAULT '' COMMENT '路由标识',
    `route_desc`  varchar(255) NOT NULL DEFAULT '' COMMENT '路由描述',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '路由表';
INSERT INTO `sys_route_cate` (`id`, `route_name`, `route_desc`)
VALUES (1, 'default', '默认路由');

CREATE TABLE `sys_route`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `route_name`  varchar(32)  NOT NULL DEFAULT '' COMMENT '路由标识',
    `alias`       varchar(255) NOT NULL DEFAULT '' COMMENT '别名',
    `url`         varchar(255) NOT NULL DEFAULT '' COMMENT 'URL',
    `ext`         varchar(10)  NOT NULL DEFAULT 'html' COMMENT '链接后缀',
    `name`        varchar(255) NOT NULL DEFAULT '' COMMENT '路由标识',
    `desc`        varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_route_name` (`route_name`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '路由详情表';
INSERT INTO `sys_route` (`id`, `route_name`, `alias`, `url`, `ext`, `name`, `desc`)
VALUES (1, 'default', 'index', 'index/index/index', 'html', 'index', '首页');

CREATE TABLE `sys_file_cate`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `name`        varchar(32)  NOT NULL DEFAULT '' COMMENT '附件标识',
    `desc`        varchar(255) NOT NULL DEFAULT '' COMMENT '附件描述',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '附件表';
INSERT INTO `sys_file_cate` (`id`, `name`, `desc`)
VALUES (1, 'default', '默认类别');

CREATE TABLE `sys_file`
(
    `id`           int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `cate_id`      int unsigned NOT NULL DEFAULT 0 COMMENT '类别ID',
    `user_id`      int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `file_size`    int unsigned NOT NULL DEFAULT 0 COMMENT '文件大小',
    `file_url`     varchar(255) NOT NULL DEFAULT '' COMMENT '文件路径',
    `file_name`    varchar(255) NOT NULL DEFAULT '' COMMENT '文件名',
    `file_desc`    varchar(255) NOT NULL DEFAULT '' COMMENT '文件描述',
    `file_ext`     varchar(20)  NOT NULL DEFAULT '' COMMENT '文件后缀',
    `file_mime`    varchar(100) NOT NULL DEFAULT '' COMMENT '文件类型',
    `file_code`    char(32)     NOT NULL DEFAULT '' COMMENT '文件唯一码',
    `file_md5`     char(32)     NOT NULL DEFAULT '' COMMENT 'md5值',
    `file_sha1`    char(64)     NOT NULL DEFAULT '' COMMENT 'sha1值',
    `extract_code` varchar(20)  NOT NULL DEFAULT '' COMMENT '提取码',
    `status`       tinyint      NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `create_time`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_file_code` (`file_code`),
    INDEX `idx_file_id` (`cate_id`) USING BTREE,
    INDEX `idx_user_id` (`user_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '附件详情表';

CREATE TABLE `sys_log`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `user_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `desc`        varchar(32)  NOT NULL DEFAULT '' COMMENT '行为描述',
    `node`        varchar(255) NOT NULL DEFAULT '' COMMENT '操作节点',
    `req_params`  text         NOT NULL COMMENT '请求参数',
    `upd_params`  text         NOT NULL COMMENT '修改参数',
    `req_result`  text         NOT NULL COMMENT '请求结果',
    `req_ip`      varchar(45)  NOT NULL DEFAULT '' COMMENT '请求IP',
    `req_method`  varchar(7)   NOT NULL DEFAULT '' COMMENT '请求类型',
    `req_ua`      varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_user_id` (`user_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci COMMENT = '日志表';
