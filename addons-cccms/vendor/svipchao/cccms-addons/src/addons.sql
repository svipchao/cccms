CREATE TABLE `sys_addons`
(
    `id`          int UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `title`       varchar(32)  NOT NULL COMMENT '插件名称(chsDash)',
    `name`        varchar(32)  NOT NULL COMMENT '插件标识(alphaNum|lower)',
    `desc`        varchar(255) NOT NULL COMMENT '插件描述(chsDash)',
    `url`         varchar(255) NOT NULL COMMENT '插件地址',
    `author`      varchar(32)  NOT NULL COMMENT '作者(chsDash)',
    `version`     varchar(32)  NOT NULL COMMENT '版本号',
    `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态(in:0,1,2,3|length:1)【0:禁用,1:启用,2:未安装,3:已安装】',
    `create_time` int                   DEFAULT 0 COMMENT '创建时间',
    `update_time` int                   DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `idx_name` (`name`),
    INDEX `idx_status` (`status`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '插件表';
INSERT INTO `sys_addons` (`id`, `title`, `name`, `desc`, `url`, `author`, `version`, `status`)
VALUES (1, '测试插件', 'demo', '测试插件描述', '#', '诗无尽头i', '1.0.0', 1);
