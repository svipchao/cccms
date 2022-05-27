import { createRouter, createWebHashHistory } from "vue-router";
import config from "@/config";
import admin from "./routes/admin.js";
import { useUser } from "@/store/user.js";

const router = createRouter({
  routes: [...admin],
  history: createWebHashHistory(),
});

// 路由守卫
router.beforeEach((to, from, next) => {
  // 更新浏览器标题
  document.title = (to.meta.title || "未命名") + " - " + (config.title || "标题");
  // 判断是否登录
  if (to.meta.login === undefined) {
    // 判断是否需要登录
    const { getAccessToken, nodes } = useUser();
    // 没有登陆状态则跳转登陆页
    if (!getAccessToken) {
      next({ path: "/login" });
    }
    // 判断是否需要权限
    if (to.meta.auth === undefined) {
      // 判断是否需要授权 以返回菜单来判断路由
      if (nodes.indexOf(to.path.substr(1)) === -1) {
        next({ path: "/403" });
      }
    }
  }
  // 解决部分页面不需要side header栏 会出现闪屏问题
  setTimeout(() => {
    document.getElementById("login-loader-main").style.display = "none";
  }, 500);
  next();
});

export default router;
