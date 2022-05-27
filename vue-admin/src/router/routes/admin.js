export default [
  {
    path: "/",
    redirect: "/index",
    component: () => import("@/layouts/base.vue"),
    children: [
      {
        path: "/index",
        component: () => import("@/pages/index/index.vue"),
        meta: {
          title: "控制台",
          auth: false, // 是否需要授权
        },
      },
      {
        path: "/admin/group/index",
        component: () => import("@/pages/admin/group/index.vue"),
        meta: { title: "组织管理" },
      },
      {
        path: "/admin/role/index",
        component: () => import("@/pages/admin/role/index.vue"),
        meta: { title: "角色管理" },
      },
      {
        path: "/admin/data/index",
        component: () => import("@/pages/admin/data/index.vue"),
        meta: { title: "数据权限" },
      },
      {
        path: "/admin/user/index",
        component: () => import("@/pages/admin/user/index.vue"),
        meta: { title: "用户管理" },
      },
      {
        path: "/admin/types/index",
        component: () => import("@/pages/admin/types/index.vue"),
        meta: { title: "公共类别" },
      },
      {
        path: "/admin/menu/index",
        component: () => import("@/pages/admin/menu/index.vue"),
        meta: { title: "菜单管理" },
      },
      {
        path: "/admin/config/index",
        component: () => import("@/pages/admin/config/index.vue"),
        meta: { title: "配置管理" },
      },
      {
        path: "/admin/route/index",
        component: () => import("@/pages/admin/route/index.vue"),
        meta: { title: "路由管理" },
      },
      {
        path: "/admin/file/index",
        component: () => import("@/pages/admin/file/index.vue"),
        meta: { title: "附件管理" },
      },
      {
        path: "/admin/log/index",
        component: () => import("@/pages/admin/log/index.vue"),
        meta: { title: "日志管理" },
      },
      {
        path: "/:pathMatch(.*)*",
        component: () => import("@/pages/result/404.vue"),
        meta: {
          title: "404",
          login: false, // 是否需要登录
          auth: false, // 是否需要授权
        },
      },
    ],
  },
  {
    path: "/login",
    component: () => import("@/pages/admin/user/login.vue"),
    meta: {
      title: "登录",
      login: false, // 是否需要登录
      auth: false, // 是否需要授权
    },
  },
];
