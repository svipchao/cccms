CREATE TABLE `sys_user`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `nickname`    varchar(32)  NOT NULL DEFAULT '' COMMENT '昵称',
    `username`    varchar(32)  NOT NULL DEFAULT '' COMMENT '用户名',
    `password`    varchar(32)  NOT NULL DEFAULT '' COMMENT '密码',
    `phone`       varchar(11)  NOT NULL DEFAULT '' COMMENT '手机号',
    `avatar`      varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
    `tags`        varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
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

CREATE TABLE `sys_dept`
(
    `id`          int unsigned  NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `dept_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '父级ID',
    `dept_ids`    varchar(2048) NOT NULL DEFAULT '' COMMENT '父级ID集合',
    `dept_name`   varchar(32)   NOT NULL DEFAULT '' COMMENT '部门名称',
    `dept_desc`   varchar(255)  NOT NULL DEFAULT '' COMMENT '部门备注',
    `post_id`     int unsigned  NOT NULL DEFAULT 0 COMMENT '默认岗位ID',
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

CREATE TABLE `sys_post`
(
    `id`          int unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `dept_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
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
    `node`    varchar(255) NOT NULL DEFAULT '' COMMENT '权限节点',
    UNIQUE INDEX `uk_role_node` (`role_id`, `node`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色权限表';

