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
       ('base', '系统配置', 'jwtKey', '诗无尽头i', 'Jwt密钥', 'input', '诗无尽头i'),
       ('base', '系统配置', 'resultPath', '../cccms/view/result.html', '返回视图路径', 'input', 'result.html'),
       ('base', '系统配置', 'middleware', 'think\\middleware\\SessionInit', '全局中间件', 'textarea', ''),
       ('node', '节点设置', 'folderPath', 'app/admin|app/index|app/cms', '需要扫描的文件夹', 'textarea', ''),
       ('node', '节点设置', 'folderAlias', 'admin,后台|index,前台|api,API', '文件夹(应用)别名', 'textarea', ''),
       ('node', '节点设置', 'ignoresFile', 'Error|view|model', '忽略的文件(夹)名 不带后缀', 'textarea', ''),
       ('node', '节点设置', 'ignoresAction', '__call|__construct', '忽略的方法', 'textarea', ''),
       ('session', 'Session', 'expire', 86400, 'Session过期时间', 'input', 86400);