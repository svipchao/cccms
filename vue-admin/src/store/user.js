import router from "@/router";
import { defineStore } from "pinia";
import { useMenu } from "@/store/menu.js";
import { login } from "@/api/admin/user.js";

export const useUser = defineStore({
  id: "user",
  state: () => ({
    // ID
    id: undefined,
    // 昵称
    nickname: undefined,
    // 用户名
    username: undefined,
    // Token
    token: undefined,
    // accessToken
    accessToken: undefined,
    // 过期时间戳
    loginExpire: undefined,
    // 管理员
    admin: undefined,
    // 角色列表
    roles: undefined,
    // 权限节点列表
    nodes: undefined,
  }),
  getters: {
    getAccessToken() {
      return this.accessToken;
    },
  },
  actions: {
    async setUserInfo(userInfo = {}, headers = {}) {
      // 获取用户信息
      await login(userInfo, headers).then((res) => {
        userInfo = res.data;
      });
      // 设置角色信息 菜单信息
      if (userInfo.role_id !== 0) {
        const menuStore = useMenu();
        menuStore.setApps(userInfo.menus);
      }
      this.$patch(userInfo);
    },
    logout() {
      this.$reset();
      const menuStore = useMenu();
      menuStore.$reset();
      // 清除accessToken
      router.push("/login");
    },
  },
  persist: {
    key: "cc_user",
    storage: window.localStorage,
  },
});
