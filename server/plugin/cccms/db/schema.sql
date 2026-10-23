-- =====================================================================
-- CCCMS 核心表结构（MySQL 8.0 / InnoDB / utf8mb4_0900_ai_ci）
--
-- 约定：
--   * create_time / update_time 由数据库默认值维护，业务代码不需要写；
--   * 业务主数据用 delete_time 软删除（NULL = 未删除），关联表 / 日志 / 配置不软删；
--   * 枚举列一律加 CHECK，脏值（手改 SQL、迁移失误）在写入时就被拒绝；
--   * JSON 列由 MySQL 校验格式，应用侧仍用 json_encode / json_decode 读写。
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 租户：单库共享表 + tenant_id 隔离。只存真实租户（id >= 1），
-- 平台租户（0）不占行，避免自增列「插入 0 取下一个值」的歧义。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_tenant` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL COMMENT '租户名称',
  `code`        varchar(64)  NOT NULL COMMENT '租户标识(全局唯一)',
  `domain`      varchar(128) DEFAULT NULL COMMENT '自定义域名(小写,不含协议与端口,NULL=未绑定)',
  `contact`     varchar(64)  NOT NULL DEFAULT '' COMMENT '联系人',
  `phone`       varchar(32)  NOT NULL DEFAULT '' COMMENT '联系电话',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `expire_at`   datetime     DEFAULT NULL COMMENT '到期时间(NULL=不过期)',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  UNIQUE KEY `uk_domain` (`domain`),
  CONSTRAINT `ck_tenant_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='租户表';

-- ---------------------------------------------------------------------
-- 用户
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user` (
  `id`           bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`    bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `username`     varchar(64)  NOT NULL COMMENT '登录账号',
  `password`     varchar(255) NOT NULL DEFAULT '' COMMENT '密码(bcrypt)',
  `nickname`     varchar(64)  NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar`       varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
  `email`        varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱',
  `phone`        varchar(32)  NOT NULL DEFAULT '' COMMENT '手机号',
  `status`       tinyint      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `remark`       varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `login_time`   datetime     DEFAULT NULL COMMENT '最后登录时间',
  `login_ip`     varchar(64)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `create_time`  datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time`  datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_user_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户表';

-- ---------------------------------------------------------------------
-- 角色（含继承与数据范围）
-- data_scope 默认 4（仅本人）：档位不继承父角色、取最宽松值，
-- 漏配时必须落到最窄档位，否则新建角色会静默拿到全库可见范围。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_role` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `name`        varchar(64)  NOT NULL COMMENT '角色名',
  `code`        varchar(64)  NOT NULL COMMENT '角色标识(唯一)',
  `data_scope`  tinyint      NOT NULL DEFAULT 4 COMMENT '数据范围 1全部 2本部门及以下 3本部门 4仅本人 5自定义',
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '父角色(继承)，0=顶级',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_role_status` CHECK (`status` IN (0, 1)),
  CONSTRAINT `ck_role_scope` CHECK (`data_scope` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='角色表';

-- ---------------------------------------------------------------------
-- 用户-角色
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_role` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户角色关联';

-- ---------------------------------------------------------------------
-- 菜单 + 权限节点树（node = slug，全局唯一）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_menu` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '父节点',
  `type`        tinyint      NOT NULL DEFAULT 1 COMMENT '1目录 2菜单 3按钮',
  `title`       varchar(64)  NOT NULL COMMENT '名称',
  `path`        varchar(128) NOT NULL DEFAULT '' COMMENT '路由地址(目录/菜单)',
  `component`   varchar(128) NOT NULL DEFAULT '' COMMENT '前端组件路径(菜单)',
  `icon`        varchar(64)  NOT NULL DEFAULT '' COMMENT '图标',
  `sort`        int          NOT NULL DEFAULT 0,
  `node`        varchar(128) NOT NULL DEFAULT '' COMMENT '权限节点标识 slug(按钮必填)',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1显示 0隐藏',
  `keep_alive`  tinyint      NOT NULL DEFAULT 0 COMMENT '是否缓存 1是 0否',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_node` (`node`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `ck_menu_type` CHECK (`type` IN (1, 2, 3)),
  CONSTRAINT `ck_menu_status` CHECK (`status` IN (0, 1)),
  CONSTRAINT `ck_menu_keep_alive` CHECK (`keep_alive` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='菜单权限节点表';

-- ---------------------------------------------------------------------
-- 角色-节点授权
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_role_node` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `node`    varchar(128) NOT NULL COMMENT 'slug',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_node` (`role_id`, `node`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='角色节点授权';

-- ---------------------------------------------------------------------
-- 部门（无限级）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_dept` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0,
  `name`        varchar(64)  NOT NULL COMMENT '部门名',
  `leader`      varchar(64)  NOT NULL DEFAULT '' COMMENT '负责人',
  `phone`       varchar(32)  NOT NULL DEFAULT '',
  `email`       varchar(128) NOT NULL DEFAULT '',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_dept_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='部门表';

-- ---------------------------------------------------------------------
-- 用户-部门（多对多）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_dept` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dept_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_dept` (`user_id`, `dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户部门关联';

-- ---------------------------------------------------------------------
-- 部门-角色
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_dept_role` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `dept_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dept_role` (`dept_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='部门角色关联';

-- ---------------------------------------------------------------------
-- 岗位
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_post` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `code`        varchar(64)  NOT NULL COMMENT '岗位编码',
  `name`        varchar(64)  NOT NULL COMMENT '岗位名',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_post_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='岗位表';

-- ---------------------------------------------------------------------
-- 用户-岗位
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_post` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `post_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_post` (`user_id`, `post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户岗位关联';

-- ---------------------------------------------------------------------
-- 数据权限规则（行级 + 字段级）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_data_rule` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `name`        varchar(64)  NOT NULL DEFAULT '' COMMENT '规则名',
  `user_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定用户 0=不限',
  `post_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定岗位 0=不限',
  `dept_ids`    json         DEFAULT NULL COMMENT '绑定部门 ID 数组；NULL=不限',
  `role_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定角色 0=不限',
  `bind_mode`   varchar(8)   NOT NULL DEFAULT 'or' COMMENT '绑定组合方式 or=任一命中 and=已填写的全部命中',
  `table_name`  varchar(64)  NOT NULL DEFAULT '' COMMENT '目标表(不含前缀)，空=不限表',
  `field`       varchar(64)  NOT NULL DEFAULT '' COMMENT '字段名',
  `action`      varchar(16)  NOT NULL DEFAULT 'row' COMMENT 'row/hidden/readonly/mask/encrypt',
  `operator`    varchar(16)  NOT NULL DEFAULT '=' COMMENT '行级操作符',
  `value`       varchar(255) NOT NULL DEFAULT '' COMMENT '行级取值(逗号分隔；动态变量如 {dept.subtree})',
  `value_type`  varchar(16)  NOT NULL DEFAULT 'static' COMMENT '取值类型 static 静态 dynamic 动态变量',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_post` (`post_id`),
  KEY `idx_role` (`role_id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_rule_bind_mode` CHECK (`bind_mode` IN ('or', 'and')),
  CONSTRAINT `ck_rule_action` CHECK (`action` IN ('row', 'hidden', 'readonly', 'mask', 'encrypt')),
  CONSTRAINT `ck_rule_operator` CHECK (`operator` IN ('=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'in', 'between')),
  CONSTRAINT `ck_rule_value_type` CHECK (`value_type` IN ('static', 'dynamic'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='数据权限规则';

-- ---------------------------------------------------------------------
-- 数据权限受控表（哪些表可以配「自定义规则」）
-- 未登记不代表没有数据权限：预设基线由模型声明决定，与登记无关。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_data_scope_table` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `table_name`  varchar(64)  NOT NULL COMMENT '表名(不含前缀)',
  `label`       varchar(64)  NOT NULL DEFAULT '' COMMENT '语义名，留空则取表注释',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1受控 0停用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table` (`table_name`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_scope_table_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='数据权限受控表';

-- ---------------------------------------------------------------------
-- 通用分类（多模块共用，按 module 区分；可层级）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_category` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `module`      varchar(32)  NOT NULL DEFAULT '' COMMENT '所属模块 dict/file',
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '上级分类，0=顶级',
  `name`        varchar(64)  NOT NULL COMMENT '分类名',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`, `parent_id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_category_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通用分类';

-- ---------------------------------------------------------------------
-- 数据字典
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_dict_type` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `category_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '分类ID(0=未分类)',
  `name`        varchar(64) NOT NULL COMMENT '字典名',
  `type`        varchar(64) NOT NULL COMMENT '字典类型标识',
  `status`      tinyint     NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime    DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime    DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type` (`type`),
  KEY `idx_category` (`category_id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_dict_type_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='字典类型';

CREATE TABLE IF NOT EXISTS `sys_dict_data` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `type_id`     bigint unsigned NOT NULL COMMENT '字典类型ID',
  `label`       varchar(64)  NOT NULL COMMENT '显示名',
  `value`       varchar(128) NOT NULL COMMENT '值',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type_id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_dict_data_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='字典数据';

-- ---------------------------------------------------------------------
-- 系统配置
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_config` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL COMMENT '配置键',
  `title`       varchar(64)  NOT NULL DEFAULT '' COMMENT '配置名',
  `type`        varchar(32)  NOT NULL DEFAULT 'input' COMMENT 'input/switch/select/input-number/textarea/radio/password',
  `value`       text         COMMENT '配置值',
  `options`     json         DEFAULT NULL COMMENT 'select/radio 的候选值数组',
  `group`       varchar(32)  NOT NULL DEFAULT '' COMMENT '分组',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  CONSTRAINT `ck_config_status` CHECK (`status` IN (0, 1)),
  CONSTRAINT `ck_config_type` CHECK (`type` IN ('input', 'switch', 'select', 'input-number', 'textarea', 'radio', 'password'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='系统配置';

-- ---------------------------------------------------------------------
-- 操作日志（登录记录 path=/auth/login）
-- 链式哈希：空串 = 未纳入链（降级写入的行）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     bigint unsigned NOT NULL DEFAULT 0,
  `username`    varchar(64)  NOT NULL DEFAULT '',
  `method`      varchar(16)  NOT NULL DEFAULT '',
  `path`        varchar(255) NOT NULL DEFAULT '',
  `node`        varchar(128) NOT NULL DEFAULT '' COMMENT '权限节点 slug',
  `title`       varchar(128) NOT NULL DEFAULT '' COMMENT '语义化操作名(取自权限注解)',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `message`     varchar(255) NOT NULL DEFAULT '' COMMENT '结果说明(登录失败原因等)',
  `trace_id`    varchar(32)  NOT NULL DEFAULT '' COMMENT '请求链路 ID',
  `action_id`   varchar(32)  NOT NULL DEFAULT '' COMMENT '操作 ID(一次用户操作的多个请求共用)',
  `session_id`  varchar(32)  NOT NULL DEFAULT '' COMMENT '会话 ID',
  `ip`          varchar(64)  NOT NULL DEFAULT '',
  `ua`          varchar(255) NOT NULL DEFAULT '',
  `params`      text         COMMENT '请求参数(query + body，已脱敏)',
  `result`      text         COMMENT '执行结果',
  `status_code` int          NOT NULL DEFAULT 200,
  `cost`        int          NOT NULL DEFAULT 0 COMMENT '耗时(ms)',
  `prev_hash`    char(64)     NOT NULL DEFAULT '' COMMENT '上一条记录的 row_hash',
  `row_hash`     char(64)     NOT NULL DEFAULT '' COMMENT '本行哈希 HMAC-SHA256(prev_hash + 规范化行内容)',
  `create_time`  datetime     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_node` (`node`),
  KEY `idx_trace_id` (`trace_id`),
  KEY `idx_action_id` (`action_id`),
  KEY `idx_session_id` (`session_id`),
  CONSTRAINT `ck_log_status` CHECK (`status` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='操作日志';

-- ---------------------------------------------------------------------
-- 审计链状态（单行表）：只查最大 id 无法发现「尾行被删」，
-- 因此链尾单独落一行；anchor 是校验起点（归档 / 清理会合法移除头部）。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_log_chain` (
  `id`          tinyint unsigned NOT NULL COMMENT '固定为 1（单行表）',
  `tail_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '链尾记录 id',
  `tail_hash`   char(64)  NOT NULL DEFAULT '' COMMENT '链尾行哈希',
  `anchor_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '校验起点 id（更早的行已归档或清理）',
  `anchor_hash` char(64)  NOT NULL DEFAULT '' COMMENT '校验起点行哈希',
  `update_time` datetime  DEFAULT NULL COMMENT '最后一次推进链的时间',
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_log_chain_single` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='审计链状态';

-- ---------------------------------------------------------------------
-- 附件
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_file` (
  `id`            bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `name`          varchar(255) NOT NULL DEFAULT '' COMMENT '存储文件名',
  `original_name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `path`          varchar(255) NOT NULL DEFAULT '' COMMENT '存储路径',
  `url`           varchar(255) NOT NULL DEFAULT '' COMMENT '访问URL',
  `category_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '分类ID(0=未分类)',
  `ext`           varchar(16)  NOT NULL DEFAULT '',
  `mime`          varchar(64)  NOT NULL DEFAULT '',
  `size`          bigint       NOT NULL DEFAULT 0 COMMENT '字节',
  `driver`        varchar(16)  NOT NULL DEFAULT 'local',
  `hash`          varchar(64)  NOT NULL DEFAULT '' COMMENT 'sha1',
  `is_public`     tinyint      NOT NULL DEFAULT 0 COMMENT '1=匿名可读 0=私有(仅登录后经下载接口)',
  `create_by`     bigint unsigned NOT NULL DEFAULT 0,
  `create_time`   datetime     DEFAULT CURRENT_TIMESTAMP,
  `delete_time`   datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_hash` (`hash`),
  KEY `idx_category` (`category_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_path` (`path`),
  CONSTRAINT `ck_file_public` CHECK (`is_public` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='附件表';

-- ---------------------------------------------------------------------
-- 定时任务
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_crontab` (
  `id`             bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`      bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `name`           varchar(64)  NOT NULL COMMENT '任务名称',
  `group_name`     varchar(32)  NOT NULL DEFAULT '' COMMENT '任务分组(仅用于归类与筛选)',
  `expression`     varchar(64)  NOT NULL COMMENT 'cron 表达式（秒 分 时 日 月 周，六段）',
  `target`         varchar(255) NOT NULL COMMENT '执行目标：实现 CrontabTask 的类名',
  `params`         json         DEFAULT NULL COMMENT '参数对象',
  `status`         tinyint      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `overlap`        varchar(8)   NOT NULL DEFAULT 'skip' COMMENT '重叠策略 skip=跳过 allow=允许并发',
  `timeout`        int          NOT NULL DEFAULT 0 COMMENT '超时秒数，0=不限',
  `retry_times`    tinyint      NOT NULL DEFAULT 0 COMMENT '失败后的重试次数',
  `retry_interval` int          NOT NULL DEFAULT 60 COMMENT '重试间隔(秒)',
  `running`        tinyint      NOT NULL DEFAULT 0 COMMENT '是否运行中 1是 0否',
  `running_at`     datetime     DEFAULT NULL COMMENT '本次开始运行时间',
  `remark`         varchar(255) NOT NULL DEFAULT '',
  `last_run_time`  datetime     DEFAULT NULL,
  `next_run_time`  datetime     DEFAULT NULL,
  `create_time`    datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time`    datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time`    datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_crontab_status` CHECK (`status` IN (0, 1)),
  CONSTRAINT `ck_crontab_running` CHECK (`running` IN (0, 1)),
  CONSTRAINT `ck_crontab_overlap` CHECK (`overlap` IN ('skip', 'allow'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='定时任务';

CREATE TABLE IF NOT EXISTS `sys_crontab_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `crontab_id`  bigint unsigned NOT NULL DEFAULT 0,
  `name`        varchar(64)  NOT NULL DEFAULT '',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1成功 0失败 2跳过(上次未结束) 3超时释放',
  `source`      varchar(16)  NOT NULL DEFAULT 'cron' COMMENT '触发来源 cron/retry/manual/timeout',
  `output`      text         COMMENT '输出 / 异常信息',
  `cost`        int          NOT NULL DEFAULT 0 COMMENT '耗时(ms)',
  `run_time`    datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crontab` (`crontab_id`),
  KEY `idx_run_time` (`run_time`),
  CONSTRAINT `ck_crontab_log_status` CHECK (`status` IN (0, 1, 2, 3)),
  CONSTRAINT `ck_crontab_log_source` CHECK (`source` IN ('cron', 'retry', 'manual', 'timeout'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='定时任务执行日志';

-- ---------------------------------------------------------------------
-- 定时任务重试队列：与 cron 调度解耦，重试不吃掉正常调度
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_crontab_retry` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `crontab_id`  bigint unsigned NOT NULL COMMENT '任务ID',
  `attempt`     tinyint        NOT NULL DEFAULT 1 COMMENT '第几次重试(1..retry_times)',
  `retry_at`    datetime       NOT NULL COMMENT '计划重试时间',
  `create_time` datetime       DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_due` (`retry_at`),
  KEY `idx_crontab` (`crontab_id`),
  CONSTRAINT `ck_crontab_retry_attempt` CHECK (`attempt` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='定时任务重试队列';

-- ---------------------------------------------------------------------
-- 异步导出任务（不参与数据权限，按 user_id 归属）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_export_task` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `type`        varchar(32)  NOT NULL DEFAULT '' COMMENT '导出类型 log/user/data_rule',
  `user_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '创建人',
  `params`      json         DEFAULT NULL COMMENT '导出参数对象',
  `status`      tinyint      NOT NULL DEFAULT 0 COMMENT '0待处理 1处理中 2完成 3失败 4已过期',
  `total_rows`  int          NOT NULL DEFAULT 0 COMMENT '导出行数',
  `file_path`   varchar(255) NOT NULL DEFAULT '' COMMENT '归档文件路径（相对 runtime）',
  `file_name`   varchar(128) NOT NULL DEFAULT '' COMMENT '下载文件名',
  `error`       varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status` (`status`),
  CONSTRAINT `ck_export_status` CHECK (`status` IN (0, 1, 2, 3, 4))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='异步导出任务';

-- ---------------------------------------------------------------------
-- 通知公告（已读状态记录在 sys_notice_read）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_notice` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `title`       varchar(128) NOT NULL COMMENT '标题',
  `type`        tinyint      NOT NULL DEFAULT 1 COMMENT '1通知 2公告',
  `level`       tinyint      NOT NULL DEFAULT 1 COMMENT '1普通 2重要',
  `content`     text         COMMENT '正文',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1已发布 0草稿',
  `scope`       tinyint      NOT NULL DEFAULT 0 COMMENT '投放范围 0全部用户 1指定部门 2指定角色 3指定用户',
  `publish_at`  datetime     DEFAULT NULL COMMENT '发布时间',
  `expire_at`   datetime     DEFAULT NULL COMMENT '过期时间(NULL=不过期)',
  `read_count`  int          NOT NULL DEFAULT 0 COMMENT '已读人数(冗余计数)',
  `create_by`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '创建人',
  `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_scope` (`scope`),
  KEY `idx_publish_at` (`publish_at`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `ck_notice_type` CHECK (`type` IN (1, 2)),
  CONSTRAINT `ck_notice_level` CHECK (`level` IN (1, 2)),
  CONSTRAINT `ck_notice_status` CHECK (`status` IN (0, 1)),
  CONSTRAINT `ck_notice_scope` CHECK (`scope` IN (0, 1, 2, 3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知公告';

CREATE TABLE IF NOT EXISTS `sys_notice_target` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `notice_id`   bigint unsigned NOT NULL COMMENT '公告ID',
  `target_type` varchar(16)  NOT NULL DEFAULT 'user' COMMENT '目标类型 dept/role/user',
  `target_id`   bigint unsigned NOT NULL COMMENT '目标ID(部门/角色/用户)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_type_target` (`notice_id`, `target_type`, `target_id`),
  KEY `idx_type_target` (`target_type`, `target_id`),
  CONSTRAINT `ck_notice_target_type` CHECK (`target_type` IN ('dept', 'role', 'user'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知公告定向投放';

CREATE TABLE IF NOT EXISTS `sys_notice_read` (
  `id`        bigint unsigned NOT NULL AUTO_INCREMENT,
  `notice_id` bigint unsigned NOT NULL,
  `user_id`   bigint unsigned NOT NULL,
  `read_time` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_user` (`notice_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知公告已读';
