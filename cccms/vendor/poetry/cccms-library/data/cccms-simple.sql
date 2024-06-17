CREATE TABLE `sys_user`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `nickname`    varchar(32)   NOT NULL DEFAULT '' COMMENT '昵称',
    `username`    varchar(32)   NOT NULL DEFAULT '' COMMENT '用户名',
    `password`    varchar(32)   NOT NULL DEFAULT '' COMMENT '密码',
    `phone`       varchar(11)   NOT NULL DEFAULT '' COMMENT '手机号',
    `avatar`      varchar(255)  NOT NULL DEFAULT '' COMMENT '头像',
    `tags`        varchar(1024) NOT NULL DEFAULT '' COMMENT '用户标签',
    `status`      tinyint       NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime      NOT NULL DEFAULT '1900-01-01 00:00:00' COMMENT '删除时间',
    `create_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_username` (`username`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户表';
INSERT INTO `sys_user` (`id`, `nickname`, `username`, `password`, `status`)
VALUES (1, '超级管理员', 'admin', '21232f297a57a5a743894a0e4a801fc3', 1);

CREATE TABLE `sys_user_dept`
(
    `user_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `dept_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
    `auth_range` tinyint(4)   NOT NULL DEFAULT 0 COMMENT '权限范围【0:本人,1:本部门,2:本部门及下属部门】',
    INDEX `idx_dept_id` (`dept_id`) USING BTREE,
    UNIQUE INDEX `uk_user_dept` (`user_id`, `dept_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户部门表';

# CREATE TABLE `sys_user_role`
# (
#     `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
#     `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
#     INDEX `idx_role_id` (`role_id`) USING BTREE,
#     UNIQUE INDEX `uk_dept_role` (`user_id`, `role_id`)
# ) ENGINE = InnoDB
#   DEFAULT CHARSET = utf8mb4
#   COLLATE = utf8mb4_general_ci COMMENT = '用户角色表';

# CREATE TABLE `sys_user_post`
# (
#     `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
#     `post_id` int unsigned NOT NULL DEFAULT 0 COMMENT '岗位ID',
#     INDEX `idx_post_id` (`post_id`) USING BTREE,
#     UNIQUE INDEX `uk_user_post` (`user_id`, `post_id`)
# ) ENGINE = InnoDB
#   DEFAULT CHARSET = utf8mb4
#   COLLATE = utf8mb4_general_ci COMMENT = '用户岗位表';

CREATE TABLE `sys_dept`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `dept_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '父级ID',
    `dept_path`   varchar(2048) NOT NULL DEFAULT '' COMMENT '父级ID集合',
    `dept_name`   varchar(32)   NOT NULL DEFAULT '' COMMENT '部门名称',
    `dept_desc`   varchar(255)  NOT NULL DEFAULT '' COMMENT '部门备注',
    # `post_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '默认岗位ID',
    `status`      tinyint       NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime      NOT NULL DEFAULT '1900-01-01 00:00:00' COMMENT '删除时间',
    `create_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门表';

CREATE TABLE `sys_dept_role`
(
    `dept_id` int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    INDEX `idx_role_id` (`role_id`) USING BTREE,
    UNIQUE INDEX `uk_dept_role` (`dept_id`, `role_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门角色表';

# CREATE TABLE `sys_post`
# (
#     `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
# #     `dept_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
#     `post_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
#     `post_name`   varchar(32)  NOT NULL DEFAULT '' COMMENT '岗位名称',
#     `post_desc`   varchar(255) NOT NULL DEFAULT '' COMMENT '岗位备注',
# #     `post_range`  tinyint(4)   NOT NULL DEFAULT 0 COMMENT '权限范围【0:本人,1:本人及下属,2:本部门,3:本部门及下属部门】',
# #     `is_default`  tinyint      NOT NULL DEFAULT 0 COMMENT '默认岗位【0:否,1:是】',
#     `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
#     `delete_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '删除时间',
#     `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
#     `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
#     PRIMARY KEY (`id`) USING BTREE
# ) ENGINE = InnoDB
#   DEFAULT CHARSET = utf8mb4
#   COLLATE = utf8mb4_general_ci COMMENT = '岗位表';

# CREATE TABLE `sys_post_role`
# (
#     `post_id` int unsigned NOT NULL DEFAULT 0 COMMENT '岗位ID',
#     `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
#     UNIQUE INDEX `uk_post_role` (`post_id`, `role_id`)
# ) ENGINE = InnoDB
#   DEFAULT CHARSET = utf8mb4
#   COLLATE = utf8mb4_general_ci COMMENT = '岗位角色表';

CREATE TABLE `sys_role`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `role_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '父级ID',
    `role_path`   varchar(2048) NOT NULL DEFAULT '' COMMENT '父级ID集合',
    `role_name`   varchar(32)   NOT NULL DEFAULT '' COMMENT '角色名称',
    `role_desc`   varchar(255)  NOT NULL DEFAULT '' COMMENT '角色备注',
    `status`      tinyint       NOT NULL DEFAULT 1 COMMENT '状态【0:禁用,1:正常】',
    `delete_time` datetime      NOT NULL DEFAULT '1900-01-01 00:00:00' COMMENT '删除时间',
    `create_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色表';

CREATE TABLE `sys_role_node`
(
    `role_id` int unsigned NOT NULL DEFAULT 0 COMMENT '角色ID',
    `node`    varchar(255) NOT NULL DEFAULT '' COMMENT '权限节点',
    UNIQUE INDEX `uk_role_node` (`role_id`, `node`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色权限表';

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
    `delete_time` datetime     NOT NULL DEFAULT '1900-01-01 00:00:00' COMMENT '删除时间',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_menu_id` (`menu_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '菜单表';
INSERT INTO `sys_menu` (`id`, `parent_id`, `menu_id`, `name`, `icon`, `url`, `node`, `layout_name`, `target`, `sort`, `status`)
VALUES (1, 0, 0, '基础系统', 'ri-stack-line', '#', '#', 'default', '_self', 0, 1),
       (2, 1, 1, '控制台', 'ri-home-line', 'admin/index/index', 'admin/index/index', 'default', '_self', 0, 1),
       (3, 1, 1, '权限配置', 'ri-shield-check-line', '#', '#', 'default', '_self', 0, 1),
       (4, 1, 3, '部门管理', '', 'admin/dept/index', 'admin/dept/index', 'default', '_self', 0, 1),
       (5, 1, 3, '角色管理', '', 'admin/role/index', 'admin/role/index', 'default', '_self', 0, 1),
       (6, 1, 3, '用户管理', '', 'admin/user/index', 'admin/user/index', 'default', '_self', 0, 1),
       (7, 1, 1, '系统配置', 'ri-settings-line', '#', '#', 'default', '_self', 0, 1),
       (8, 1, 7, '系统设置', '', 'admin/config/index', 'admin/config/index', 'default', '_self', 0, 1),
       (9, 1, 7, '菜单管理', '', 'admin/menu/index', 'admin/menu/index', 'default', '_self', 0, 1),
       (10, 1, 7, '附件管理', '', 'admin/file/index', 'admin/file/index', 'default', '_self', 0, 1),
       (11, 1, 7, '日志管理', '', 'admin/log/index', 'admin/log/index', 'default', '_self', 0, 1);

CREATE TABLE `sys_config_cate`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `cate_name`   varchar(32)  NOT NULL DEFAULT '' COMMENT '配置标识',
    `cate_desc`   varchar(255) NOT NULL DEFAULT '' COMMENT '配置描述',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '配置表';
INSERT INTO `sys_config_cate` (`id`, `cate_name`, `cate_desc`)
VALUES (1, 'site', '网站配置'),
       (2, 'captcha', '验证码配置'),
       (3, 'log', '日志配置'),
       (4, 'storage', '上传配置'),
       (5, 'watermark', '水印配置');

CREATE TABLE `sys_config`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `cate_name`   varchar(32)   NOT NULL DEFAULT '' COMMENT '配置标识',
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
INSERT INTO `sys_config` (`id`, `cate_name`, `name`, `label`, `value`, `configure`)
VALUES (1, 'site', 'siteName', '网站名称', '诗无尽头i', '{"type":"input","placeholder":"请输入网站名称","default":"默认站点","description":"请输入网站名称"}'),
       (2, 'site', 'siteIcp', '备案号', '豫ICP备93093369号', '{"type":"input","placeholder":"请输入网站备案号","default":"豫ICP备93093369号","description":"请输入网站备案号"}'),
       (3, 'captcha', 'open', '启用验证码', 1, '{"type":"switch","default":1,"description":"是否启用验证码","options":{"checked":1,"unchecked":0}}'),
       (4, 'captcha', 'math', '验证码类型', 1, '{"type":"select","placeholder":"请选择验证码类型","default":0,"description":"请选择验证码类型","options":[{"value":"0","label":"普通验证码"},{"value":"1","label":"算数验证码"}]}'),
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

CREATE TABLE `sys_file_cate`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `cate_name`   varchar(32)  NOT NULL DEFAULT '' COMMENT '附件标识',
    `cate_desc`   varchar(255) NOT NULL DEFAULT '' COMMENT '附件描述',
    `sort`        int          NOT NULL DEFAULT 0 COMMENT '排序',
    `delete_time` datetime     NOT NULL DEFAULT '1900-01-01 00:00:00' COMMENT '删除时间',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '附件表';
INSERT INTO `sys_file_cate` (`id`, `cate_name`, `cate_desc`)
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
    `name`        varchar(255) NOT NULL DEFAULT '' COMMENT '行为名称',
    `node`        varchar(255) NOT NULL DEFAULT '' COMMENT '操作节点',
    `req_ip`      varchar(45)  NOT NULL DEFAULT '' COMMENT '请求IP',
    `req_method`  varchar(7)   NOT NULL DEFAULT '' COMMENT '请求类型',
    `req_ua`      varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
    `create_time` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_user_id` (`user_id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci COMMENT = '日志表';

CREATE TABLE `sys_log_info`
(
    `log_id`     int unsigned NOT NULL COMMENT 'ID',
    `req_params` longtext     NOT NULL COMMENT '请求参数',
    `upd_params` longtext     NOT NULL COMMENT '修改参数',
    `req_result` longtext     NOT NULL COMMENT '请求结果',
    UNIQUE KEY `idx_log_id` (`log_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci COMMENT = '日志详情表';
