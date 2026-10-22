-- =====================================================================
-- CCCMS 核心表结构（MySQL 8 / InnoDB / utf8mb4）
-- 时间戳：create_time / update_time 由数据库默认值自动写入
--         （DEFAULT CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP），
--         业务代码用查询构造器也不会漏写。
-- 软删除：业务主数据带 `delete_time`（NULL = 未删除），见 support/SoftDelete 与「回收站」；
--         关联表 / 日志 / 配置不做软删除。
--         带唯一键的表（user/role/post/dict_type）软删后唯一值仍被占用，
--         要复用该值需先在回收站里「彻底删除」。
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 租户（多租户隔离的边界；`tenant_id = 0` 是平台/默认租户，不落库，仅虚拟存在）
--
-- 隔离方式：**单库共享表 + tenant_id 列**。模型层
-- （`BaseModel::$globalScope` 的 `tenant` 作用域）自动给查询加租户条件，
-- 写入时由 `Support\TenantContext` 强制写入当前租户 —— 详见 docs/06-数据权限。
--
-- 本表只存**真实租户**（id >= 1）；平台租户（0）不占行，避免 MySQL
-- 自增列「插入 0 即取下一个自增值」带来的语义歧义。
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
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  UNIQUE KEY `uk_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='租户表';

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
  `create_time`  datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time`  datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ---------------------------------------------------------------------
-- 角色（含继承与数据范围）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_role` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `name`        varchar(64)  NOT NULL COMMENT '角色名',
  `code`        varchar(64)  NOT NULL COMMENT '角色标识(唯一)',
  -- 默认 4（仅本人）而非 1（全部数据）：档位不继承父角色，靠「取最宽松」生效，
  -- 所以漏配时必须是**最窄**的兜底，否则新建角色会静默拿到全库可见范围（fail-closed 兜底）。
  `data_scope`  tinyint      NOT NULL DEFAULT 4 COMMENT '数据范围 1全部 2本部门及以下 3本部门 4仅本人 5自定义',
  `parent_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '父角色(继承)，0=顶级',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色表';

-- ---------------------------------------------------------------------
-- 用户-角色
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_role` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联';

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
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_node` (`node`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='菜单权限节点表';

-- ---------------------------------------------------------------------
-- 角色-节点授权
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_role_node` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `node`    varchar(128) NOT NULL COMMENT 'slug',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_node` (`role_id`, `node`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色节点授权';

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
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部门表';

-- ---------------------------------------------------------------------
-- 用户-部门（多对多）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_dept` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `dept_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_dept` (`user_id`, `dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户部门关联';

-- ---------------------------------------------------------------------
-- 部门-角色
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_dept_role` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `dept_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dept_role` (`dept_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部门角色关联';

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
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='岗位表';

-- ---------------------------------------------------------------------
-- 用户-岗位
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user_post` (
  `id`      bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `post_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_post` (`user_id`, `post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户岗位关联';

-- ---------------------------------------------------------------------
-- 数据权限规则（行级 + 字段级）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_data_rule` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `name`        varchar(64)  NOT NULL DEFAULT '' COMMENT '规则名',
  `user_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定用户 0=不限',
  `post_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定岗位 0=不限',
  `dept_ids`    text         COMMENT '绑定部门(多选,跨部门)；JSON 数组字符串，为 NULL 表示不限',
  `role_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '绑定角色 0=不限',
  `bind_mode`   varchar(8)   NOT NULL DEFAULT 'or' COMMENT '绑定组合方式 or=任一命中 and=已填写的全部命中',
  `table_name`  varchar(64)  NOT NULL DEFAULT '' COMMENT '目标表(不含前缀)，空=不限表',
  `field`       varchar(64)  NOT NULL DEFAULT '' COMMENT '字段名',
  `action`      varchar(16)  NOT NULL DEFAULT 'row' COMMENT 'row/hidden/readonly/mask/encrypt',
  `operator`    varchar(16)  NOT NULL DEFAULT '=' COMMENT '行级操作符 = != in like > >= < <= between',
  `value`       varchar(255) NOT NULL DEFAULT '' COMMENT '行级取值(逗号分隔；动态变量如 {dept.subtree})',
  `value_type`  varchar(16)  NOT NULL DEFAULT 'static' COMMENT '取值类型 static静态 dynamic动态变量',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_post` (`post_id`),
  KEY `idx_role` (`role_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据权限规则';

-- ---------------------------------------------------------------------
-- 数据权限受控表（哪些表可以配「自定义规则」；未列入的表在规则页被隐藏）
-- 登记的前提是该表已接入数据权限（模型声明参与 + 查询走模型），否则规则不会生效；
-- 未登记不代表没有数据权限：预设基线（仅本人/本部门）由模型声明决定，与登记无关
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_data_scope_table` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `table_name`  varchar(64)  NOT NULL COMMENT '表名(不含前缀)',
  `label`       varchar(64)  NOT NULL DEFAULT '' COMMENT '语义名，留空则取表注释',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1受控 0停用',
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table` (`table_name`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据权限受控表';

-- ---------------------------------------------------------------------
-- 通用分类（多模块共用，按 module 区分；可层级）
-- 目前：module=dict 字典类型分类、module=file 附件分类
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
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`, `parent_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通用分类';

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
  `create_time` datetime    NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime    DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type` (`type`),
  KEY `idx_category` (`category_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='字典类型';

CREATE TABLE IF NOT EXISTS `sys_dict_data` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `type_id`     bigint unsigned NOT NULL COMMENT '字典类型ID',
  `label`       varchar(64)  NOT NULL COMMENT '显示名',
  `value`       varchar(128) NOT NULL COMMENT '值',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type_id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='字典数据';

-- ---------------------------------------------------------------------
-- 系统配置
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_config` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL COMMENT '配置键',
  `title`       varchar(64)  NOT NULL DEFAULT '' COMMENT '配置名',
  `type`        varchar(32)  NOT NULL DEFAULT 'input' COMMENT 'input/switch/select/input-number/textarea/radio',
  `value`       text         COMMENT '配置值',
  `options`     text         COMMENT '可选值(select/radio)；JSON 数组字符串',
  `group`       varchar(32)  NOT NULL DEFAULT '' COMMENT '分组',
  `sort`        int          NOT NULL DEFAULT 0,
  `status`      tinyint      NOT NULL DEFAULT 1,
  `remark`      varchar(255) NOT NULL DEFAULT '',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置';

-- ---------------------------------------------------------------------
-- 操作日志
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     bigint unsigned NOT NULL DEFAULT 0,
  `username`    varchar(64)  NOT NULL DEFAULT '',
  `method`      varchar(16)  NOT NULL DEFAULT '',
  `path`        varchar(255) NOT NULL DEFAULT '',
  `node`        varchar(128) NOT NULL DEFAULT '' COMMENT '权限节点 slug(路径语义化标识)',
  `title`       varchar(128) NOT NULL DEFAULT '' COMMENT '语义化操作名(取自权限注解)',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `message`     varchar(255) NOT NULL DEFAULT '' COMMENT '结果说明(登录失败原因等)',
  `trace_id`    varchar(32)  NOT NULL DEFAULT '' COMMENT '请求链路 ID(一次 HTTP 请求一条记录)',
  `action_id`   varchar(32)  NOT NULL DEFAULT '' COMMENT '操作 ID(一次用户操作触发的多个请求共用，链路视图按它聚合)',
  `session_id`  varchar(32)  NOT NULL DEFAULT '' COMMENT '会话 ID(刷新/重开页面即换，用于审计轨迹)',
  `ip`          varchar(64)  NOT NULL DEFAULT '',
  `ua`          varchar(255) NOT NULL DEFAULT '',
  `params`      text         COMMENT '请求参数(query + body，已脱敏)',
  `result`      text         COMMENT '执行结果',
  `status_code` int          NOT NULL DEFAULT 200,
  `cost`        int          NOT NULL DEFAULT 0 COMMENT '耗时(ms)',
  -- 链式哈希（防篡改）：空串 = 未纳入链（本能力上线前的历史行，或被降级写入的行）
  `prev_hash`    char(64)     NOT NULL DEFAULT '' COMMENT '上一条记录的 row_hash',
  `row_hash`     char(64)     NOT NULL DEFAULT '' COMMENT '本行内容哈希 sha256(prev_hash + 规范化的行内容)',
  `hash_version` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '哈希字段集版本(0=16 字段；1=含 action_id/session_id)',
  `create_time`  datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_node` (`node`),
  KEY `idx_trace_id` (`trace_id`),
  KEY `idx_action_id` (`action_id`),
  KEY `idx_session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='日志(操作 + 登录；登录记录 path=/auth/login)';

-- ---------------------------------------------------------------------
-- 审计链状态（**单行表**，id 固定为 1）
--
-- 只靠「查 sys_log 的最大 id」无法发现**尾行被删**，而删尾正是最常见的掩盖手段，
-- 因此链尾单独落一行；`anchor_id` 是校验起点：归档 / 清理 / 管理员删除都会
-- 把链的**头部**拿掉，那是合法操作，不能与篡改混为一谈。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_log_chain` (
  `id`          tinyint unsigned NOT NULL COMMENT '固定为 1（单行表）',
  `tail_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '链尾记录 id',
  `tail_hash`   char(64)  NOT NULL DEFAULT '' COMMENT '链尾行哈希',
  `anchor_id`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '校验起点 id（更早的行已归档或清理）',
  `anchor_hash` char(64)  NOT NULL DEFAULT '' COMMENT '校验起点行哈希',
  `update_time` datetime  DEFAULT NULL COMMENT '最后一次推进链的时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='审计链状态';

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
  `is_public`     tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1=匿名可读(直链可访问) 0=私有(仅登录后经下载接口，默认)',
  `create_by`     bigint unsigned NOT NULL DEFAULT 0,
  `create_time`   datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `delete_time`   datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_hash` (`hash`),
  KEY `idx_category` (`category_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='附件表';

-- ---------------------------------------------------------------------
-- 定时任务（二期）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_crontab` (
  `id`             bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id`      bigint unsigned NOT NULL DEFAULT 0 COMMENT '租户ID(0=平台)',
  `name`           varchar(64)  NOT NULL COMMENT '任务名称',
  `group_name`     varchar(32)  NOT NULL DEFAULT '' COMMENT '任务分组(仅用于归类与筛选)',
  `expression`     varchar(64)  NOT NULL COMMENT 'cron 表达式（秒 分 时 日 月 周，六段）',
  `target`         varchar(255) NOT NULL COMMENT '执行目标：实现 CrontabTask 的类名',
  `params`         text         COMMENT '参数数组；JSON 对象字符串',
  `status`         tinyint      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `overlap`        varchar(8)   NOT NULL DEFAULT 'skip' COMMENT '重叠策略 skip=上次未结束则跳过 allow=允许并发',
  `timeout`        int          NOT NULL DEFAULT 0 COMMENT '超时秒数，0=不限；超时会释放运行锁并记为超时',
  `retry_times`    tinyint      NOT NULL DEFAULT 0 COMMENT '失败后的重试次数',
  `retry_interval` int          NOT NULL DEFAULT 60 COMMENT '重试间隔(秒)',
  `running`        tinyint      NOT NULL DEFAULT 0 COMMENT '是否运行中(运行时) 1是 0否',
  `running_at`     datetime     DEFAULT NULL COMMENT '本次开始运行时间(运行时)',
  `remark`         varchar(255) NOT NULL DEFAULT '',
  `last_run_time`  datetime     DEFAULT NULL,
  `next_run_time`  datetime     DEFAULT NULL,
  `create_time`    datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time`    datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time`    datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务';

CREATE TABLE IF NOT EXISTS `sys_crontab_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `crontab_id`  bigint unsigned NOT NULL DEFAULT 0,
  `name`        varchar(64)  NOT NULL DEFAULT '',
  `status`      tinyint      NOT NULL DEFAULT 1 COMMENT '1成功 0失败 2跳过(上次未结束) 3超时释放',
  `source`      varchar(16)  NOT NULL DEFAULT 'cron' COMMENT '触发来源 cron/retry/manual',
  `output`      text         COMMENT '输出 / 异常信息',
  `cost`        int          NOT NULL DEFAULT 0 COMMENT '耗时(ms)',
  `run_time`    datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crontab` (`crontab_id`),
  KEY `idx_run_time` (`run_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务执行日志';

-- ---------------------------------------------------------------------
-- 定时任务独立重试队列（与 cron 调度严格解耦）
--
-- 失败后把「重试」作为独立任务投递到这里，由调度进程单独消费；
-- 重试只按自己的 retry_at 触发，不影响任务本身的 cron 时间轴 ——
-- 避免「重试吃掉一次正常调度」（旧实现把 retry_left/retry_at 写在 sys_crontab 上，
-- 重试与 cron 同秒命中时会让 cron 被跳过）。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_crontab_retry` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `crontab_id`  bigint unsigned NOT NULL COMMENT '任务ID',
  `attempt`     tinyint        NOT NULL DEFAULT 1 COMMENT '第几次重试(1..retry_times)',
  `retry_at`    datetime       NOT NULL COMMENT '计划重试时间',
  `create_time` datetime       NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_due` (`retry_at`),
  KEY `idx_crontab` (`crontab_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定时任务独立重试队列';

-- ---------------------------------------------------------------------
-- 异步导出任务（超阈值导出转后台消费；不参与数据权限，按 user_id 归属）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_export_task` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `type`        varchar(32)  NOT NULL DEFAULT '' COMMENT '导出类型 log/user/data_rule',
  `user_id`     bigint unsigned NOT NULL DEFAULT 0 COMMENT '创建人',
  `params`      text         COMMENT '导出参数 JSON',
  `status`      tinyint      NOT NULL DEFAULT 0 COMMENT '0待处理 1处理中 2完成 3失败 4已过期',
  `total_rows`  int          NOT NULL DEFAULT 0 COMMENT '导出行数',
  `file_path`   varchar(255) NOT NULL DEFAULT '' COMMENT '归档文件路径（相对 runtime）',
  `file_name`   varchar(128) NOT NULL DEFAULT '' COMMENT '下载文件名',
  `error`       varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='异步导出任务';

-- ---------------------------------------------------------------------
-- 通知公告（广播给所有登录用户；已读状态记录在 sys_notice_read）
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
  `expire_at`   datetime     DEFAULT NULL COMMENT '过期时间(NULL=不过期)，过期后不在「我的消息」展示',
  `read_count`  int          NOT NULL DEFAULT 0 COMMENT '已读人数(冗余计数)',
  `create_by`   bigint unsigned NOT NULL DEFAULT 0 COMMENT '创建人',
  `create_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime     DEFAULT NULL COMMENT '删除时间(NULL=未删除)',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_scope` (`scope`),
  KEY `idx_publish_at` (`publish_at`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知公告';

CREATE TABLE IF NOT EXISTS `sys_notice_target` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `notice_id`   bigint unsigned NOT NULL COMMENT '公告ID',
  `target_type` varchar(16)  NOT NULL DEFAULT 'user' COMMENT '目标类型 dept/role/user',
  `target_id`   bigint unsigned NOT NULL COMMENT '目标ID(部门/角色/用户)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_type_target` (`notice_id`, `target_type`, `target_id`),
  KEY `idx_type_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知公告定向投放';

CREATE TABLE IF NOT EXISTS `sys_notice_read` (
  `id`        bigint unsigned NOT NULL AUTO_INCREMENT,
  `notice_id` bigint unsigned NOT NULL,
  `user_id`   bigint unsigned NOT NULL,
  `read_time` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_user` (`notice_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知公告已读';

SET FOREIGN_KEY_CHECKS = 1;
