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



select distinct node
from sys_role_node
where role_id in (select role_id
                  from sys_post_role
                  where post_id in (select post_id
                                    from sys_user_dept_post
                                    where user_id = 2 and post_id in (select id from sys_post where `status` = 1))
                  union
                  select role_id
                  from sys_dept_role
                  where dept_id in (select dept_id
                                    from sys_user_dept_post
                                    where user_id = 2 and dept_id in (select id from sys_dept where `status` = 1)));


SELECT DISTINCT rnode.node
FROM sys_role_node AS rnode
         JOIN (select dept_id, role_id from sys_dept_role union select post_id, role_id from sys_post_role) AS drole
              ON rnode.role_id = drole.role_id

         JOIN sys_user_dept_post AS udep ON drole.dept_id = udep.post_id

         JOIN sys_user_dept_post AS udept ON drole.dept_id = udept.dept_id
         JOIN sys_post AS post ON udep.post_id = post.id AND post.status = 1

         JOIN sys_dept AS dept ON udept.dept_id = dept.id AND dept.status = 1
WHERE udep.user_id = 2
   OR udept.user_id = 2;
