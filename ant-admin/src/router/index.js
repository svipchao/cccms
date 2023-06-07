import { createRouter, createWebHashHistory } from "vue-router";
import config from "@/config";
import { useTabs } from "@/store/admin/tabs.js";
import { useMenu } from "@/store/admin/menu.js";
import { useUser } from "@/store/admin/user.js";
import { useSystem } from "@/store/admin/system.js";
import { expandArray } from "@/utils/array.js";

const router = createRouter({
  routes: [
    {
      name: "layouts",
      path: "/",
      redirect: "/admin/index/index",
      component: () => import("@/layouts/index.vue"),
      children: [
        {
          path: "/admin/index/index",
          component: () => import("@/pages/admin/index/index.vue"),
          meta: { title: "首页" },
        },
        {
          path: "/:pathMatch(.*)*",
          component: () => import("@/layouts/result/404.vue"),
          meta: { title: "404" },
        },
      ],
    },
    {
      name: "login",
      path: "/login",
      component: () => import("@/pages/admin/login/index.vue"),
      meta: { title: "登录" },
    },
  ],
  history: createWebHashHistory(),
});

// 路由守卫
router.beforeEach((to, from, next) => {
  // 判断是否需要登录
  const userStore = useUser();
  if (userStore.accessToken) {
    if (to.name == "login") {
      next();
    } else {
      // 未登录 跳转登录页
      next({ path: "/login" });
    }
  } else {
    // 解决刷新页面路由不生效问题
    const systemStore = useSystem();
    if (systemStore.isRegisterRouteFresh) {
      const menuStore = useMenu();
      const menus = expandArray(menuStore.menus || []);
      menus.forEach((item) => {
        if (item.node !== "#") {
          router.addRoute("layouts", {
            name: item.node,
            path: "/" + item.url,
            meta: { id: item.id, icon: item.icon, title: item.name },
            component: async () => {
              let cpn = await import(
                /* @vite-ignore */ "../pages/" + item.node + ".vue"
              );
              cpn.default.name = item.node;
              return cpn;
            },
          });
        }
      });
      next({ ...to, replace: true });
      systemStore.setRegisterRouteFresh();
    } else {
      // keep-alive 实现
      const tabsStore = useTabs();
      const tab = tabsStore.getCacheTab(to.name);
      if (!tab && to.name) {
        tabsStore.cacheTabs.push(to.name);
      }
      next();
    }
  }
  // 更新浏览器标题
  document.title =
    (to.meta.title || "未命名") + " - " + (config.title || "标题");
  // 解决部分页面不需要side header栏 会出现闪屏问题
  setTimeout(() => {
    document.getElementById("cccms-loader-home").style.display = "none";
  }, 500);
});

export default router;
