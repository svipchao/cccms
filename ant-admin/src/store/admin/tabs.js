import router from '@/router';
import { message } from 'ant-design-vue';
import { defineStore } from 'pinia';
import { useMenu } from './menu.js';

export const useTabs = defineStore({
  id: 'tabs',
  state: () => ({
    tabs: [],
    cacheTabs: [],
    isRefresh: false,
  }),
  getters: {
    getCacheTabs(state) {
      return state.cacheTabs;
    },
  },
  actions: {
    getTab(id = undefined) {
      id = id !== undefined ? id : this.getMenuSelected();
      const tabs = this.tabs;
      for (let index in tabs) {
        if (tabs[index]['id'] == id) {
          return { index: index, tab: tabs[index] };
        }
      }
      return false;
    },
    getCacheTab(val) {
      const cacheTabs = this.cacheTabs;
      for (let index in cacheTabs) {
        if (cacheTabs[index] == val) {
          return { index: index, tab: cacheTabs[index] };
        }
      }
      return false;
    },
    setMenuSelected(id) {
      const menuStore = useMenu();
      menuStore.selectedKeys = id;
    },
    getMenuSelected() {
      const menuStore = useMenu();
      return menuStore.selectedKeys;
    },
    switchTab(id) {
      // 设置菜单选中项
      const menuStore = useMenu();
      const menu = menuStore.getMenu(id);
      console.log(menu);
      if (menu) {
        // 重新赋值 有可能传进来的不是id 而是node
        id = menu.id;
        router.push('/' + menu.url);
        console.log(menu.url);
        if (menuStore.showApps) {
          // 如果选择的是应用 则切换Menu数据
          menuStore.currentAppId = id;
          menuStore.showApps = false;
          menuStore.currentMenus = menuStore.getCurrentMenus;
          menuStore.selectedKeys = menuStore.currentMenuKey;
        } else {
          this.setMenuSelected(id);
          const data = this.getTab(id);
          if (!data) {
            menu.app_id = menuStore.currentAppId;
            this.tabs.push(menu);
          }
          // 切换Tab的同时切换应用ID
          menuStore.currentAppId = menu.app_id;
        }
      } else {
        message.error('标签不存在');
      }
    },
    closeTab(id) {
      if (this.tabs.length > 1) {
        const tab = this.getTab(id);
        this.tabs.splice(tab.index, 1);
        // 判断关闭的是否当前tab 不是的话不需要切换tab
        if (id === this.getMenuSelected()) {
          // 判断标签下标是否存在 不存在则指向第一个标签
          if (this.tabs[tab.index - 1]) {
            this.switchTab(this.tabs[tab.index - 1]['id']);
          } else {
            this.switchTab(this.tabs[0]['id']);
          }
        }
        // 删除缓存标签
        const cacheTab = this.getCacheTab(tab.tab.node);
        this.cacheTabs.splice(cacheTab.index, 1);
      } else {
        message.error('请不要删除最后一个标签');
      }
    },
    closeLeftTab() {
      const data = this.getTab();
      this.tabs.splice(0, data.index);
    },
    closeRightTab() {
      const data = this.getTab();
      this.tabs.splice(Number(data.index) + 1, this.tabs.length);
    },
    closeOtherTab() {
      const data = this.getTab();
      this.tabs.splice(0, data.index);
      this.tabs.splice(Number(data.index) + 1, this.tabs.length);
    },
    closeAllTab() {
      this.tabs.splice(1, this.tabs.length);
      this.switchTab(0);
    },
    refreshTab() {
      const tab = this.getTab();
      if (tab) {
        const cacheTab = this.getCacheTab(tab.tab.node);
        if (cacheTab) {
          this.cacheTabs.splice(cacheTab.index, 1);
        }
        this.isRefresh = !this.isRefresh;
        setTimeout(() => {
          this.isRefresh = !this.isRefresh;
        }, 10);
      } else {
        message.error('请选中标签后刷新');
      }
    },
  },
  persist: true,
});
