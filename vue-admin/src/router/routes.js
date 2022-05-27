export default [
  {
    name: "首页",
    path: "/",
    redirect: "/index",
    component: () => import("@/layouts/base.vue"),
    children: [
      {
        name: "控制台",
        path: "/index",
        meta: {
          auth: false, // 是否需要授权
        },
        component: () => import("@/pages/index/index.vue"),
      },
      // 权限管理
      {
        name: "组织管理",
        path: "/admin/group/index",
        component: () => import("@/pages/admin/group/index.vue"),
      },
      {
        name: "角色管理",
        path: "/admin/role/index",
        component: () => import("@/pages/admin/role/index.vue"),
      },
      {
        name: "数据权限",
        path: "/admin/data/index",
        component: () => import("@/pages/admin/data/index.vue"),
      },
      {
        name: "用户管理",
        path: "/admin/user/index",
        component: () => import("@/pages/admin/user/index.vue"),
      },
      {
        name: "公共类别",
        path: "/admin/types/index",
        component: () => import("@/pages/admin/types/index.vue"),
      },
      {
        name: "菜单管理",
        path: "/admin/menu/index",
        component: () => import("@/pages/admin/menu/index.vue"),
      },
      {
        name: "配置管理",
        path: "/admin/config/index",
        component: () => import("@/pages/admin/config/index.vue"),
      },
      {
        name: "路由管理",
        path: "/admin/route/index",
        component: () => import("@/pages/admin/route/index.vue"),
      },
      {
        name: "附件管理",
        path: "/admin/file/index",
        component: () => import("@/pages/admin/file/index.vue"),
      },
      {
        name: "日志管理",
        path: "/admin/log/index",
        component: () => import("@/pages/admin/log/index.vue"),
      },
      {
        name: "404",
        path: "/:pathMatch(.*)*",
        meta: {
          login: false, // 是否需要登录
          auth: false, // 是否需要授权
        },
        component: () => import("@/pages/result/404.vue"),
      },
    ],
  },
  {
    name: "登录",
    path: "/login",
    meta: {
      login: false, // 是否需要登录
      auth: false, // 是否需要授权
    },
    component: () => import("@/pages/admin/user/login.vue"),
  },
];
