INSERT INTO `sys_user` (`id`, `nickname`, `username`, `password`, `status`)
VALUES (2, '测试用户1', 'admin888', '21232f297a57a5a743894a0e4a801fc3', 1),
       (3, '测试用户2', 'admin999', '21232f297a57a5a743894a0e4a801fc3', 1);

INSERT INTO `sys_user_dept_post` (`user_id`, `dept_id`, `post_id`)
VALUES (2, 1, 1),
       (2, 2, 1),
       (3, 1, 1),
       (3, 2, 1);

INSERT INTO `sys_dept` (`id`, `dept_id`, `dept_ids`, `dept_name`, `dept_desc`, `post_id`, `status`)
VALUES (1, 0, '1', '部门1', '部门1', 1, 1),
       (2, 0, '1', '部门2', '部门2', 2, 1);

INSERT INTO `sys_dept_role` (`dept_id`, `role_id`)
VALUES (1, 1),
       (1, 2),
       (2, 1),
       (2, 2);

INSERT INTO `sys_post` (`id`, `dept_id`, `post_name`, `post_desc`, `post_range`, `is_default`)
VALUES (1, 1, '部门1-默认岗位', '部门1-默认岗位', 0, 1),
       (2, 1, '部门1-本人及下属', '部门1-本人及下属', 1, 1),
       (3, 1, '部门1-本部门', '部门1-本部门', 2, 1),
       (4, 1, '部门1-本人及下属部门', '部门1-本人及下属部门', 3, 1),
       (5, 2, '部门2-默认岗位', '部门2-默认岗位', 0, 1);

INSERT INTO `sys_post_role` (`post_id`, `role_id`)
VALUES (1, 1),
       (2, 1);

INSERT INTO `sys_role` (`id`, `role_id`, `role_ids`, `role_name`, `role_desc`, `status`)
VALUES (1, 0, '1', '角色1', '角色1', 1),
       (2, 0, '1', '角色2', '角色2', 1),
       (3, 0, '1', '角色3', '角色3', 1);

INSERT INTO `sys_role_node` (`role_id`, `node`)
VALUES (1, 'node_1_1'),
       (1, 'node_1_2'),
       (1, 'node_1_3'),
       (2, 'node_2_1'),
       (2, 'node_2_2'),
       (2, 'node_2_3'),
       (2, 'node_1_3'),
       (3, 'node_3_1'),
       (3, 'node_3_2'),
       (3, 'node_3_3');
