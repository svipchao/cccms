-- 数据权限规则的「表 + 字段」组合索引。
--
-- 背景：规则编译（每次请求）、列表按表名筛选、写入前查重，都按 (table_name, field) 过滤，
-- 原来只有 idx_user / idx_post / idx_role / idx_tenant 四个绑定维度索引，这两个条件只能回表全扫。
-- 规则表数据量不大（通常几十行），但编译发生在**每个请求**上，索引收益稳定。
--
-- 幂等说明：新装环境的 schema.sql 已包含同一索引，重复执行会命中
-- 「Duplicate key name」，由 SqlFileRunner 的迁移模式按「已生效」跳过。
ALTER TABLE `sys_data_rule`
  ADD KEY `idx_table_field` (`table_name`, `field`);
