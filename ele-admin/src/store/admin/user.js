import router from "@/router";
import { defineStore } from "pinia";
import { useMenu } from "./menu.js";
import { useTabs } from "./tabs.js";
import { useTheme } from "./theme.js";
import { useSystem } from "./system.js";
import { login, refreshToken } from "@/api/admin/login.js";
import { ElMessage } from "element-plus";

export const useUser = defineStore({
  id: "user",
  state: () => ({
    // ID
    id: 0,
    // 昵称
    nickname: "",
    // 用户名
    username: "",
    // accessToken
    accessToken: "",
    // 过期时间戳
    login_expire: 0,
    // 权限节点列表
    nodes: [],
    // 是否注册路由
    isRegisterRouteFresh: true,
  }),
  getters: {
    getFirstNickname() {
      return this.nickname.substr(0, 1);
    },
  },
  actions: {
    async setUserInfo(userInfo = {}) {
      await login(userInfo).then((res) => {
        const menuStore = useMenu();
        menuStore.setMenus(res.data.menus);
        delete res.data.menus;
        delete res.data.is_admin;
        this.$patch(res.data);
        ElMessage.success({
          content: "登录成功",
          onClose: () => {
            const tabsStore = useTabs();
            tabsStore.switchTab("admin/index/index");
          },
        });
      });
    },
    async setAccessToken() {
      await refreshToken().then((res) => {
        const menuStore = useMenu();
        menuStore.setMenus(res.data.menus);
        this.$patch(res.data);
        const systemStore = useSystem();
        systemStore.setRegisterRouteFresh();
        ElMessage.success("缓存清除成功");
      });
    },
    logout() {
      ElMessage.success({
        content: "注销登录成功",
        onClose: () => {
          router.push("/login");
          this.$reset();
          const menuStore = useMenu();
          menuStore.$reset();
          const tabsStore = useTabs();
          tabsStore.$reset();
          const themeStore = useTheme();
          themeStore.$reset();
        },
      });
    },
  },
  persist: true,
});
