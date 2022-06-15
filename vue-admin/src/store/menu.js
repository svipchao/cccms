import { defineStore } from "pinia";

export const useMenu = defineStore({
  id: "menu",
  state: () => ({
    // 应用列表
    menus: undefined,
    // 当前应用
    currentAppId: undefined,
    // 当前应用菜单
    currentMenus: undefined,
    // 打开的菜单目录
    openKeys: undefined,
    // 选中的菜单
    selectedKeys: undefined,
  }),
  getters: {
    getOpenKeys() {
      return Number(this.openKeys);
    },
    getSelectedKeys() {
      return Number(this.selectedKeys);
    },
    getCurrentAppId() {
      const currentAppId = Number(this.currentAppId);
      return this.getCurrentMenu !== undefined ? currentAppId : undefined;
    },
    getCurrentMenu() {
      const currentAppId = Number(this.currentAppId);
      for (let index in this.menus) {
        let app = this.menus[index];
        if (currentAppId == app.id) {
          this.currentMenus = app.menus;
          return app.menus;
        }
      }
      return undefined;
    },
  },
  actions: {
    setApps(menus) {
      this.menus = menus;
    },
    setCurrentApp(currentAppId) {
      this.currentAppId = currentAppId;
    },
    // 打开的菜单目录
    setOpenKeys(key, openKeys) {
      this.openKeys = openKeys;
    },
    // 选中的菜单
    setSetSelectedKeys(selectedKeys) {
      this.selectedKeys = selectedKeys;
    },
  },
  persist: {
    key: "cc_menu",
    storage: window.localStorage,
  },
});
