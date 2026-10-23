-- =====================================================================
-- CCCMS 初始化种子数据（在 schema.sql 之后执行）
-- 默认管理员：admin / admin123（首次登录后请务必修改）
-- 全部使用 INSERT IGNORE / NOT EXISTS 保证可重复执行。
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- 超管角色（code 唯一，不可改不可删）
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_role` (`id`, `name`, `code`, `data_scope`, `parent_id`, `sort`, `status`, `remark`, `create_time`, `update_time`) VALUES
(1, '超级管理员', 'super_admin', 1, 0, 0, 1, '系统内置超管角色', NOW(), NOW());

-- ---------------------------------------------------------------------
-- 默认管理员账号
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_user` (`id`, `username`, `password`, `nickname`, `status`, `remark`, `create_time`, `update_time`) VALUES
(1, 'admin', '$2y$10$R2WLwa0R5vUGIAOPgfDhsuDiKn418dMCwoGgilKdzo7mJbEvqv0VC', '超级管理员', 1, '系统内置管理员', NOW(), NOW());

-- ---------------------------------------------------------------------
-- 管理员绑定超管角色
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_user_role` (`user_id`, `role_id`) VALUES (1, 1);

-- ---------------------------------------------------------------------
-- 数据权限受控表（默认登记基础系统里已接入数据权限的表）
--
-- 登记 = 可以在规则页给它配「自定义规则」；未登记的表仍受预设基线保护。
-- 新增业务插件接入数据权限后，在自己的 seed 里登记即可（有唯一键，可重复执行）。
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_data_scope_table` (`table_name`, `label`, `status`, `remark`, `create_time`, `update_time`) VALUES
('user',    '',       1, '已接入：用户管理', NOW(), NOW()),
('file',    '附件',    1, '模型声明参与：owner=create_by，本部门落到可见部门成员', NOW(), NOW()),
('log',     '操作日志', 1, '模型声明参与：owner=user_id，本部门落到可见部门成员', NOW(), NOW()),
('crontab', '定时任务', 1, '模型声明 no_baseline（无归属列），隔离依赖自定义规则', NOW(), NOW()),
('dept',    '部门',    1, '模型声明参与；「本部门及以下」档下部门页只见自己子树（见 docs/06）', NOW(), NOW());

-- ---------------------------------------------------------------------
-- 内置定时任务：清理历史操作日志
--
-- 需要它「自动清理日志」配置（log.auto_clean / log.keep_days）才真正生效。
-- 用 INSERT ... SELECT ... WHERE NOT EXISTS 实现幂等（sys_crontab 没有可去重的唯一键，
-- 因此不能用 INSERT IGNORE）。
-- ---------------------------------------------------------------------
INSERT INTO `sys_crontab` (`name`, `expression`, `target`, `params`, `status`, `group_name`, `overlap`, `timeout`, `retry_times`, `retry_interval`, `remark`, `create_time`, `update_time`)
SELECT '清理历史日志', '0 0 3 * * *', 'plugin\\cccms\\command\\task\\LogCleanTask', NULL, 1, '系统', 'skip', 600, 1, 300, '每天 03:00 清理 log.keep_days 之前的操作日志', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `sys_crontab` WHERE `target` = 'plugin\\cccms\\command\\task\\LogCleanTask'
);

-- 分片上传的临时文件回收（每小时）：只清 24 小时未继续写入的会话，不依赖任何外部服务
INSERT INTO `sys_crontab` (`name`, `expression`, `target`, `params`, `status`, `group_name`, `overlap`, `timeout`, `retry_times`, `retry_interval`, `remark`, `create_time`, `update_time`)
SELECT '清理分片上传临时文件', '0 30 * * * *', 'plugin\\cccms\\command\\task\\ChunkCleanTask', NULL, 1, '系统', 'skip', 600, 1, 300, '每小时 30 分清理 24 小时未完成的分片上传临时目录', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `sys_crontab` WHERE `target` = 'plugin\\cccms\\command\\task\\ChunkCleanTask'
);

-- 消费异步导出任务（每 10 秒）：超阈值导出转后台生成归档文件，顺带清理过期导出
INSERT INTO `sys_crontab` (`name`, `expression`, `target`, `params`, `status`, `group_name`, `overlap`, `timeout`, `retry_times`, `retry_interval`, `remark`, `create_time`, `update_time`)
SELECT '消费异步导出任务', '*/10 * * * * *', 'plugin\\cccms\\command\\task\\ExportTaskConsumer', NULL, 1, '系统', 'skip', 600, 1, 300, '每 10 秒生成待处理的导出归档文件，并清理 export.keep_minutes 之前的过期导出', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `sys_crontab` WHERE `target` = 'plugin\\cccms\\command\\task\\ExportTaskConsumer'
);

-- ---------------------------------------------------------------------
-- 内置定时任务：归档历史日志到对象存储
--
-- **默认停用（status=0）**：归档是破坏性操作（上传成功后删主库），
-- 需运维确认对象存储可用后再手动启用。参数取 plugin/cccms/config/log.php 的 archive 段。
-- 同样用 INSERT ... SELECT ... WHERE NOT EXISTS 保证幂等。
-- ---------------------------------------------------------------------
INSERT INTO `sys_crontab` (`name`, `expression`, `target`, `params`, `status`, `group_name`, `overlap`, `timeout`, `retry_times`, `retry_interval`, `remark`, `create_time`, `update_time`)
SELECT '日志归档到对象存储', '0 0 4 * * *', 'plugin\\cccms\\command\\task\\LogArchiveTask', NULL, 0, '系统', 'skip', 3600, 1, 300, '每天 04:00 归档早于 log.archive.days 的日志到对象存储（默认停用，确认存储可用后启用）', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `sys_crontab` WHERE `target` = 'plugin\\cccms\\command\\task\\LogArchiveTask'
);

-- ---------------------------------------------------------------------
-- 内置定时任务：数据库定时备份（F3）
--
-- **默认停用（status=0）**：备份会占用磁盘与数据库读 IO，且保留策略需要运维确认，
-- 由管理员在「定时任务」里启用并调整表达式（默认每天 02:30）。
-- 保留策略取 backup.keep_count / backup.keep_days，见 BackupLogic::prune()。
-- ---------------------------------------------------------------------
INSERT INTO `sys_crontab` (`name`, `expression`, `target`, `params`, `status`, `group_name`, `overlap`, `timeout`, `retry_times`, `retry_interval`, `remark`, `create_time`, `update_time`)
SELECT '数据库定时备份', '0 30 2 * * *', 'plugin\\cccms\\command\\task\\DbBackupTask', NULL, 0, '系统', 'skip', 3600, 1, 600, '每天 02:30 备份数据库到 runtime/backups（默认停用，确认磁盘与保留策略后启用）', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `sys_crontab` WHERE `target` = 'plugin\\cccms\\command\\task\\DbBackupTask'
);

-- ---------------------------------------------------------------------
-- 系统配置默认值
--
-- 这些配置项定义了系统的「可配置面」。
-- 当前状态：**已全部被后端业务代码消费**（品牌/界面/安全/上传/日志），
-- 保存后立即生效（SysConfig 走 Redis 缓存，保存时 flush），无需重启。
-- 已确认「不做密钥轮换」的 data_encrypt_key 不在此表，它在 config/auth.php。
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sys_config` (`name`, `title`, `type`, `value`, `options`, `group`, `sort`, `status`, `remark`, `create_time`, `update_time`) VALUES
-- 系统
('system.name',                 '系统名称',        'input',        'CCCMS',                        NULL, '系统', 1, 1, '显示在浏览器标题与登录页', NOW(), NOW()),
('system.logo',                 '系统 Logo',       'input',        '',                             NULL, '系统', 2, 1, '图片 URL，留空则使用内置 Logo（站点根 logo.png）', NOW(), NOW()),
('system.icp',                  '备案号',          'input',        '',                             NULL, '系统', 3, 1, '显示在前台页脚', NOW(), NOW()),
('system.copyright',            '版权信息',        'textarea',     '© 2026 CCCMS',                 NULL, '系统', 4, 1, '支持多行', NOW(), NOW()),
('system.maintenance',          '维护模式',        'switch',       '0',                            NULL, '系统', 5, 1, '开启后前台展示维护公告', NOW(), NOW()),
('system.maintenance_notice',   '维护公告',        'textarea',     '系统维护中，请稍后访问',        NULL, '系统', 6, 1, '维护模式下展示的内容', NOW(), NOW()),

-- 界面
('ui.theme_mode',               '默认主题模式',    'radio',        'light',                        '[{"label":"亮色","value":"light"},{"label":"暗色","value":"dark"},{"label":"跟随系统","value":"auto"}]', '界面', 1, 1, '新用户首次进入时的主题', NOW(), NOW()),
('ui.theme_primary',            '默认主色',        'input',        '#2b6cff',                      NULL, '界面', 2, 1, '十六进制色值，如 #2b6cff', NOW(), NOW()),
('ui.page_size',                '默认分页条数',    'input-number', '15',                           NULL, '界面', 3, 1, '列表页每页默认条数', NOW(), NOW()),
('ui.tags_view',                '显示多标签页',    'switch',       '1',                            NULL, '界面', 4, 1, '关闭后内容区只保留单页', NOW(), NOW()),
('ui.container_width',          '内容区最大宽度',  'input-number', '0',                            NULL, '界面', 5, 1, '单位 px，0 表示占满', NOW(), NOW()),

-- 安全
('security.login_captcha',      '登录验证码',      'switch',       '0',                            NULL, '安全', 1, 1, '开启后登录必须输入图形验证码', NOW(), NOW()),
('security.login_fail_limit',   '失败锁定次数',    'input-number', '5',                            NULL, '安全', 2, 1, '同一账号连续登录失败达到该次数后锁定账号；0 表示关闭', NOW(), NOW()),
('security.login_fail_ip_limit','同 IP 失败上限',  'input-number', '20',                           NULL, '安全', 3, 1, '同一 IP 连续登录失败达到该次数后限流该 IP（不锁账号，避免误伤同出口 IP 的同事）；0 表示关闭', NOW(), NOW()),
('security.login_fail_window',  '锁定时长',        'input-number', '15',                           NULL, '安全', 4, 1, '单位分钟，超时后自动解锁', NOW(), NOW()),
('security.token_ttl',          '令牌有效期',      'input-number', '7200',                          NULL, '安全', 5, 1, '单位秒，默认 2 小时；剩余不足 1/3 时自动滑动续期（用户无感），调大可延长 XSS 有效窗口', NOW(), NOW()),
('security.password_min_length','密码最小长度',    'input-number', '6',                            NULL, '安全', 6, 1, '新增、编辑、重置密码与个人改密时校验', NOW(), NOW()),
('security.password_max_length','密码最大长度',    'input-number', '64',                           NULL, '安全', 7, 1, '超长口令会放大 bcrypt 开销，构成低成本 DoS；0 表示不限制', NOW(), NOW()),
('security.password_strength', '密码字符类别数',  'input-number', '2',                            NULL, '安全', 8, 1, '需包含大写字母/小写字母/数字/符号中的几类；0 表示不要求。内置弱口令黑名单与「不得包含用户名等身份信息」始终生效', NOW(), NOW()),
('security.rate_limit_enable', '接口限流开关',    'switch',       '1',                            NULL, '安全', 9, 1, '开启后按「每用户每路由」限流，接口被高频刷取时返回 429', NOW(), NOW()),
('security.rate_limit_limit',  '普通接口次数',    'input-number', '60',                           NULL, '安全', 10, 1, '每用户每路由每分钟允许的调用次数；0 表示不限制', NOW(), NOW()),
('security.rate_limit_heavy_limit','重接口次数',   'input-number', '5',                            NULL, '安全', 11, 1, '导出/导入/代码生成等重接口每用户每路由每分钟次数；0 表示不限制', NOW(), NOW()),
('security.rate_limit_window', '限流窗口',        'input-number', '60',                           NULL, '安全', 12, 1, '单位秒，计数窗口长度', NOW(), NOW()),
('security.rate_limit_whitelist','限流白名单',     'input',        '',                              NULL, '安全', 20, 1, '逗号分隔的路径前缀，命中即不限流、不计入限流计数（如 /open/callback/ 表示该前缀下所有接口放行）；留空 = 全部按限流规则执行', NOW(), NOW()),

-- 上传
('upload.max_size',             '单文件上限',      'input-number', '10',                           NULL, '上传', 1, 1, '单位 MB；单请求直传的上限。大文件请走分片上传（前端自动切换）', NOW(), NOW()),
('upload.chunk_max_size',       '分片上传上限',    'input-number', '2048',                        NULL, '上传', 2, 1, '单位 MB；分片上传合并后文件的总大小上限（建议远大于单文件上限）', NOW(), NOW()),
('upload.ext_allow',            '允许的扩展名',    'input',        'jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,md,zip,rar,7z,mp3,mp4,webm', NULL, '上传', 3, 1, '英文逗号分隔，不要带点；留空则用 filesystem.php 的默认白名单。⚠️ 不要加入 svg/html/xml：本地驱动同域直出，会被浏览器内联渲染成存储型 XSS', NOW(), NOW()),
('upload.image_ext',            '图片扩展名',      'input',        'jpg,jpeg,png,gif,webp',        NULL, '上传', 4, 1, '需要按图片处理的扩展名', NOW(), NOW()),
('upload.storage_driver',       '存储驱动',        'select',       'local',                        '[{"label":"本地","value":"local"},{"label":"阿里云 OSS","value":"oss"},{"label":"腾讯云 COS","value":"cos"},{"label":"七牛云","value":"qiniu"}]', '上传', 5, 1, '选中哪个驱动，本页只显示该驱动的凭证项；凭证加密存库，不需要写 .env', NOW(), NOW()),
('upload.url_prefix',           '访问前缀',        'input',        '/storage',                     NULL, '上传', 6, 1, '切独立域名 / CDN 时改这里', NOW(), NOW()),

-- 附件匿名访问的总开关（单个附件还有自己的 is_public；这里是"一票否决"）
('upload.anonymous',            '允许匿名访问附件', 'switch',      '1',                            NULL, '上传', 21, 1, '关闭后所有附件都必须登录后经下载接口读取；开启时按单个附件的「公开」状态放行。⚠️ /storage 必须反代到后端，Nginx 直接托管会绕过该开关', NOW(), NOW()),

-- 上传 · 阿里云 OSS（driver=oss 时必填；密钥类项加密存储，留空 = 保持原值）
('upload.oss_access_key_id',     'AccessKey ID',    'input',        '',                             NULL, '上传', 7, 1, '阿里云账号的 AccessKey ID', NOW(), NOW()),
('upload.oss_access_key_secret', 'AccessKey Secret','password',     '',                             NULL, '上传', 8, 1, 'AccessKey Secret，加密存储', NOW(), NOW()),
('upload.oss_bucket',            'Bucket',          'input',        '',                             NULL, '上传', 9, 1, 'OSS Bucket 名称', NOW(), NOW()),
('upload.oss_endpoint',          'Endpoint',        'input',        '',                             NULL, '上传', 10, 1, '如 oss-cn-hangzhou.aliyuncs.com（不带 bucket 前缀）', NOW(), NOW()),
('upload.oss_domain',            '自定义域名',      'input',        '',                             NULL, '上传', 11, 1, 'CDN / 自定义域名，留空则用 endpoint + bucket 拼装', NOW(), NOW()),

-- 上传 · 腾讯云 COS（driver=cos 时必填）
('upload.cos_secret_id',         'SecretId',        'input',        '',                             NULL, '上传', 12, 1, '腾讯云 API 密钥 SecretId', NOW(), NOW()),
('upload.cos_secret_key',        'SecretKey',       'password',     '',                             NULL, '上传', 13, 1, '腾讯云 API 密钥 SecretKey，加密存储', NOW(), NOW()),
('upload.cos_bucket',            'Bucket',          'input',        '',                             NULL, '上传', 14, 1, '控制台显示的存储桶名称（形如 name-1250000000）；访问时驱动自动拼接 -Region 后缀', NOW(), NOW()),
('upload.cos_region',            'Region',          'input',        '',                             NULL, '上传', 15, 1, '地域，如 ap-guangzhou', NOW(), NOW()),
('upload.cos_domain',            '自定义域名',      'input',        '',                             NULL, '上传', 16, 1, 'CDN / 自定义域名，留空则用 bucket + region 拼装', NOW(), NOW()),

-- 上传 · 七牛云 Kodo（driver=qiniu 时必填）
('upload.qiniu_access_key',      'AccessKey',       'input',        '',                             NULL, '上传', 17, 1, '七牛云账号的 AccessKey', NOW(), NOW()),
('upload.qiniu_secret_key',      'SecretKey',       'password',     '',                             NULL, '上传', 18, 1, '七牛云账号的 SecretKey，加密存储', NOW(), NOW()),
('upload.qiniu_bucket',          'Bucket',          'input',        '',                             NULL, '上传', 19, 1, '存储空间名称', NOW(), NOW()),
('upload.qiniu_domain',          '访问域名',        'input',        '',                             NULL, '上传', 20, 1, '空间绑定的测试域名或自定义域名（必填，七牛不提供默认 URL 拼装）', NOW(), NOW()),

-- 日志
('log.keep_days',               '日志保留天数',    'input-number', '30',                           NULL, '日志', 1, 1, '定时任务按此天数清理历史日志', NOW(), NOW()),
('log.auto_clean',              '自动清理日志',    'switch',       '1',                            NULL, '日志', 2, 1, '关闭后需手工清理', NOW(), NOW()),
('log.record_read',             '记录查询操作',    'switch',       '0',                            NULL, '日志', 3, 1, '开启后 GET 请求也会写入操作日志', NOW(), NOW()),
('log.slow_threshold',          '慢接口告警阈值',  'input-number', '3000',                         NULL, '日志', 4, 1, '单位毫秒；请求耗时超过该值时写慢接口告警日志(slow.log)，0 表示关闭', NOW(), NOW()),
('export.keep_minutes',         '导出文件保留时长','input-number', '60',                           NULL, '日志', 5, 1, '单位分钟；异步导出的归档文件保留多久，过期即删并置为「已过期」', NOW(), NOW()),

-- 监控（F6：服务器资源看板；阈值同时用于页面标红与 Prometheus 告警规则）
('monitor.cpu_threshold',       'CPU 告警阈值',    'input-number', '85',                           NULL, '监控', 1, 1, '单位 %；看板超过即标红，建议与 Prometheus 告警规则保持同一阈值', NOW(), NOW()),
('monitor.memory_threshold',    '内存告警阈值',    'input-number', '90',                           NULL, '监控', 2, 1, '单位 %；系统内存使用率（MemTotal - MemAvailable）', NOW(), NOW()),
('monitor.disk_threshold',      '磁盘告警阈值',    'input-number', '90',                           NULL, '监控', 3, 1, '单位 %；runtime 目录所在分区的已用比例', NOW(), NOW()),
('monitor.connections_threshold','数据库连接阈值',  'input-number', '80',                           NULL, '监控', 4, 1, '单位 %；MySQL 连接数占 max_connections 的比例', NOW(), NOW()),

-- 备份（F3：数据库备份 / 还原）
('backup.keep_count',           '定时备份保留份数','input-number', '10',                           NULL, '备份', 1, 1, '定时备份最多保留几份，超出按时间删最旧的；手工备份不受此限（要留多久由人决定）', NOW(), NOW()),
('backup.keep_days',            '备份保留天数',    'input-number', '30',                           NULL, '备份', 2, 1, '所有已完成备份超过该天数即清理（含还原前的自动备份）；0 表示不按天数清理', NOW(), NOW()),

-- 找回密码（渠道开关默认关闭，最安全；开启前请先配好 邮箱 / 短信 分组）
('security.reset_channel',      '找回密码渠道',    'select',       'off',                          '[{"label":"关闭","value":"off"},{"label":"邮箱","value":"email"},{"label":"短信","value":"sms"},{"label":"邮箱 + 短信","value":"both"}]', '安全', 13, 1, '找回密码可用的验证码通道；off 表示关闭找回入口', NOW(), NOW()),
('security.reset_code_ttl',     '验证码有效期',    'input-number', '300',                          NULL, '安全', 14, 1, '单位秒，验证码在 Redis 中的存活时间，最小 60', NOW(), NOW()),
('security.reset_send_interval','发送间隔',        'input-number', '60',                           NULL, '安全', 15, 1, '单位秒，同一账号两次发送验证码的最小间隔；0 表示不限制', NOW(), NOW()),
('security.reset_max_attempts', '验证码尝试上限',  'input-number', '5',                            NULL, '安全', 16, 1, '同一验证码最多校验几次，达到上限即作废，防暴力猜码', NOW(), NOW()),
('security.reset_daily_limit',  '每日发送上限',    'input-number', '10',                           NULL, '安全', 17, 1, '同一账号每天最多发送几次验证码；0 表示不限制', NOW(), NOW()),
('security.trusted_proxies',    '可信代理',        'textarea',     '',                             NULL, '安全', 18, 1, '反向代理/网关的 IP 或 CIDR，逗号或换行分隔（如 172.18.0.0/16）；仅这些来源的 X-Forwarded-For 会被采信以溯源真实客户端 IP，留空表示不采信任何转发头（直接用直连 IP）', NOW(), NOW()),
('security.metrics_allow_ips',  '可观测端点白名单','textarea',     '127.0.0.1,::1',                NULL, '安全', 19, 1, '允许访问 /healthz 与 /metrics 的 IP 或 CIDR，逗号或换行分隔（如 172.18.0.0/16）；非白名单来源一律返回 404。留空等同 127.0.0.1,::1（仅容器内探活可用）', NOW(), NOW()),

-- 邮箱（找回密码 · 邮件通道；密码字段用 type=password，加密存储）
('mail.enabled',                '启用邮箱通道',    'switch',       '0',                            NULL, '邮箱', 1, 1, '开启后支持邮箱找回密码，还需在「安全」分组把找回渠道设为邮箱或邮箱+短信', NOW(), NOW()),
('mail.host',                   'SMTP 服务器',     'input',        '',                             NULL, '邮箱', 2, 1, 'SMTP 主机名，如 smtp.example.com', NOW(), NOW()),
('mail.port',                   'SMTP 端口',       'input-number', '465',                          NULL, '邮箱', 3, 1, 'SSL 常用 465，STARTTLS 常用 587', NOW(), NOW()),
('mail.username',               'SMTP 账号',       'input',        '',                             NULL, '邮箱', 4, 1, '登录 SMTP 的用户名，留空表示不需要认证', NOW(), NOW()),
('mail.password',               'SMTP 密码',       'password',     '',                             NULL, '邮箱', 5, 1, '登录 SMTP 的密码 / 授权码，加密存储', NOW(), NOW()),
('mail.encryption',             '加密方式',        'select',       'ssl',                          '[{"label":"SSL","value":"ssl"},{"label":"STARTTLS","value":"tls"},{"label":"不加密","value":"none"}]', '邮箱', 6, 1, 'ssl=直接加密连接，tls=STARTTLS 升级，none=明文（不推荐）', NOW(), NOW()),
('mail.from_address',           '发件人邮箱',      'input',        '',                             NULL, '邮箱', 7, 1, '留空则使用 SMTP 账号', NOW(), NOW()),
('mail.from_name',              '发件人名称',      'input',        'CCCMS',                        NULL, '邮箱', 8, 1, '展示在收件人邮件客户端', NOW(), NOW()),

-- 短信（找回密码 · 短信通道；通用 HTTP 网关，不绑定厂商）
('sms.enabled',                 '启用短信通道',    'switch',       '0',                            NULL, '短信', 1, 1, '开启后支持短信找回密码，还需在「安全」分组把找回渠道设为短信或邮箱+短信', NOW(), NOW()),
('sms.driver',                  '短信驱动',        'input',        'http',                         NULL, '短信', 2, 1, '通用 HTTP 网关驱动标识（预留）', NOW(), NOW()),
('sms.gateway_url',             '网关地址',        'input',        '',                             NULL, '短信', 3, 1, '短信服务商提供的发送接口地址', NOW(), NOW()),
('sms.method',                  '请求方法',        'select',       'POST',                         '[{"label":"POST","value":"POST"},{"label":"GET","value":"GET"}]', '短信', 4, 1, 'GET 时参数拼到 query，POST 时作为 JSON 请求体发送', NOW(), NOW()),
('sms.params',                  '参数模板',        'textarea',     '{"mobile":"{mobile}","code":"{code}","sign":"{sign}"}', NULL, '短信', 5, 1, 'JSON 对象；值里的 {mobile}/{code}/{sign} 会被替换为该次发送的实际值', NOW(), NOW()),
('sms.headers',                 '请求头',          'textarea',     '{}',                           NULL, '短信', 6, 1, 'JSON 对象，如 {"Authorization":"Bearer xxx"}；POST 未指定 Content-Type 时默认 application/json', NOW(), NOW()),
('sms.sign_name',               '短信签名',        'input',        '',                             NULL, '短信', 7, 1, '短信签名，替换参数模板里的 {sign}', NOW(), NOW()),

-- 水印（登录后的前台页面叠加，防截图外泄；登录页不在 BasicLayout 内，不叠加）
('watermark.enabled',           '启用水印',        'switch',       '0',                            NULL, '水印', 1, 1, '开启后全站页面叠加水印（登录前的页面不叠加）', NOW(), NOW()),
('watermark.content',           '水印内容',        'textarea',     '{username}{newline}{time}',    NULL, '水印', 2, 1, '支持变量（点下方标签插入）：用户名 {用户名}/{username}、用户ID {用户ID}/{user_id}、时间 {时间}/{time}、换行 {换行}/{newline}；中英文写法都识别，也可直接回车换行', NOW(), NOW()),
('watermark.opacity',           '不透明度',        'input-number', '10',                           NULL, '水印', 3, 1, '单位 %，建议 5-20', NOW(), NOW()),
('watermark.angle',             '倾斜角度',        'input-number', '-22',                          NULL, '水印', 4, 1, '单位度，负值向左倾斜', NOW(), NOW()),
('watermark.font_size',         '字号',            'input-number', '14',                           NULL, '水印', 5, 1, '单位 px', NOW(), NOW()),
('watermark.color',             '颜色',            'input',        '#8c8c8c',                      NULL, '水印', 6, 1, '十六进制色值，如 #8c8c8c；暗色主题建议用浅色', NOW(), NOW()),
('watermark.gap_x',             '水平间距',        'input-number', '180',                          NULL, '水印', 7, 1, '单位 px，相邻水印中心的水平间距', NOW(), NOW()),
('watermark.gap_y',             '垂直间距',        'input-number', '140',                          NULL, '水印', 8, 1, '单位 px，相邻水印中心的垂直间距', NOW(), NOW());
