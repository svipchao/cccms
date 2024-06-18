INSERT INTO `sys_user` (`id`, `nickname`, `username`, `password`, `status`)
VALUES (2, '测试用户1', 'admin888', '21232f297a57a5a743894a0e4a801fc3', 1),
       (3, '测试用户2', 'admin999', '21232f297a57a5a743894a0e4a801fc3', 1);

INSERT INTO `sys_user_dept` (`user_id`, `dept_id`, `auth_range`)
VALUES (2, 1, 1),
       (2, 2, 2),
       (3, 1, 1),
       (3, 2, 2);

INSERT INTO `sys_dept` (`id`, `dept_id`, `dept_path`, `dept_name`, `dept_desc`, `status`)
VALUES (1, 0, ',1,', '部门1', '部门1', 1),
       (2, 0, ',2,', '部门2', '部门2', 1),
       (3, 1, ',1,3,', '部门3', '部门3', 1),
       (4, 3, ',1,3,4,', '部门4', '部门4', 1),
       (5, 3, ',1,3,5,', '部门5', '部门5', 1),
       (6, 2, ',2,6,', '部门6', '部门6', 1);

INSERT INTO `sys_dept_role` (`dept_id`, `role_id`)
VALUES (1, 1),
       (1, 2),
       (2, 1),
       (2, 2);

# INSERT INTO `sys_post` (`id`, `dept_id`, `post_name`, `post_desc`, `post_range`, `is_default`)
# VALUES (1, 1, '部门1-默认岗位', '部门1-默认岗位', 0, 1),
#        (2, 1, '部门1-本人及下属', '部门1-本人及下属', 1, 1),
#        (3, 1, '部门1-本部门', '部门1-本部门', 2, 1),
#        (4, 1, '部门1-本人及下属部门', '部门1-本人及下属部门', 3, 1),
#        (5, 2, '部门2-默认岗位', '部门2-默认岗位', 0, 1);
#
# INSERT INTO `sys_post_role` (`post_id`, `role_id`)
# VALUES (1, 1),
#        (2, 1);

INSERT INTO `sys_role` (`id`, `role_id`, `role_path`, `role_name`, `role_desc`, `status`)
VALUES (1, 0, '1', '角色1', '角色1', 1),
       (2, 0, '1', '角色2', '角色2', 1),
       (3, 0, '1', '角色3', '角色3', 1);

INSERT INTO `sys_role_node` (`role_id`, `node`)
VALUES (1, 'admin/user/create'),
       (1, 'admin/user/delete'),
       (1, 'admin/user/update'),
       (1, 'admin/user/index'),
       (2, 'admin/role/create'),
       (2, 'admin/role/delete'),
       (2, 'admin/role/update'),
       (2, 'admin/role/index'),
       (3, 'admin/dept/create'),
       (3, 'admin/dept/delete'),
       (3, 'admin/dept/update'),
       (3, 'admin/dept/index');

SELECT `node`
FROM `sys_role_node`
WHERE `role_id` IN (SELECT `role_id` FROM `sys_dept_role` WHERE `dept_id` IN (1, 2))
  AND `role_id` NOT IN (SELECT `id` FROM `sys_role` WHERE `status` = 0)