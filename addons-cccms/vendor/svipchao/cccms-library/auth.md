用户
设定角色(多角色)

角色
设定权限
无限上下级
数据权限
表字段权限

组织
无限上下级
设定角色
设定用户

1. 角色数据权限
   1、不同的角色身份查看的角色数据时不相同的，比如物业分公司中深圳区域分公司的管理人员可能就无法管理长沙区域分公司，在给角色分配数据权限时就可以将长沙区域分公司去除。
   2、除数据权限外，我们还会遇到字段权限，比如：分公司C和分公司D都能看到上海区域分公司的客户情况，但是C看不到客户联系方式，D则能看到联系方式。如果有需要对字段权限进行控制，则可以在设置角色的数据权限或者功能权限时，进行控制。
   3、题前有提到针对saas模式，可能存在一个角色在管理A跟B应用时可操作的数据权限时不一样的，可以在数据权限中增加一个高级设置权限，为不同的角色针对不用的应用进行分配数据操作。

参考资料：http://www.woshipm.com/pd/2310691.html


角色权限 role_node

用户角色 user_role
组织角色 group_role
用户组织 user_group

基础方法
分页    limit()
筛选状态 status()

公共方法
获取指定用户      getUser()
获取所有用户      getUsers()
获取用户拥有的权限 getUserToNodes()
获取用户拥有的角色 getUserToRoles()
获取用户拥有的组织 getUserToGroups()

判断用户是否拥有组织 hasUserToGroup()
判断用户是否拥有角色 hasUserToRole()
判断用户是否拥有权限 hasUserToNode()

获取指定角色    getRole()
获取所有角色    getRoles()
获取角色下的权限 getRoleToNodes()
获取角色下属角色 getRoleToRoles()
获取角色下的用户 getRoleToUsers()
获取角色下的组织 getRoleToGroups()

获取指定组织    getGroup()
获取所有组织    getGroups()
获取组织下的权限 getGroupToNodes()
获取组织下的角色 getGroupToRoles()
获取组织下的用户 getGroupToUsers()
获取组织下属组织 getGroupToGroups()