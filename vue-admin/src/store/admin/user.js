import router from '@/router';
import { defineStore } from 'pinia';
import { useMenu } from './menu.js';
import { useTabs } from './tabs.js';
import { useTheme } from './theme.js';
import { useSystem } from './system.js';
import { refreshToken } from '@/api/admin/login.js';
import { Message } from '@arco-design/web-vue';

export const useUser = defineStore({
  id: 'user',
  state: () => ({
    // ID
    id: 0,
    // 昵称
    nickname: '',
    // 用户名
    username: '',
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
      const menuStore = useMenu();
      menuStore.setMenus(userInfo.menus);
      delete userInfo.menus;
      delete userInfo.is_admin;
      this.$patch(userInfo);
      Message.success({
        content: '登录成功',
        onClose: () => {
          const tabsStore = useTabs();
          tabsStore.switchTab(userInfo.home_url);
        },
      });
    },
    async setAccessToken() {
      await refreshToken().then((res) => {
        const menuStore = useMenu();
        menuStore.setMenus(res.data.menus);
        this.$patch(res.data);
        const systemStore = useSystem();
        systemStore.setRegisterRouteFresh();
        Message.success('缓存清除成功');
      });
    },
    logout(isTip = true) {
      if (isTip) {
        Message.success({
          content: '注销成功',
        });
      }
      router.push('/login');
      this.$reset();
      const menuStore = useMenu();
      menuStore.$reset();
      const tabsStore = useTabs();
      tabsStore.$reset();
      const themeStore = useTheme();
      themeStore.$reset();
    },
  },
  persist: true,
});
