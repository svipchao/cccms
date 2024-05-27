import { defineStore } from 'pinia';
import { useMenuStore } from './menu.js';
import { useTabsStore } from './tabs.js';
import { useThemeStore } from './theme.js';
import { Message } from '@arco-design/web-vue';

export const useUserStore = defineStore('user', {
  state: () => ({
    // ID
    id: 0,
    // 昵称
    nickname: '',
    // 用户名
    username: '',
    // 用户名
    phone: '',
    // 用户名
    email: '',
    // 主页
    home_url: '',
    // accessToken
    accessToken: '',
    // 过期时间戳
    login_expire: 0,
    // 权限节点列表
    nodes: [],
    // 是否注册路由
    isRegisterRouteFresh: true,
    // 系统配置
    configs: [],
  }),
  getters: {
    getFirstNickname() {
      return this.nickname.substr(0, 1);
    },
  },
  actions: {
    setUserInfo(userInfo = {}) {
      const menuStore = useMenuStore();
      menuStore.setMenus(userInfo.menus);
      delete userInfo.menus;
      delete userInfo.is_admin;
      this.$patch(userInfo);
      Message.success({
        content: '登录成功',
        onClose: () => {
          const tabsStore = useTabsStore();
          tabsStore.switchTab(userInfo.home_url);
        },
      });
    },
    setRegisterRouteFresh() {
      this.isRegisterRouteFresh = !this.isRegisterRouteFresh;
    },
  },
  persist: true,
});
