import { defineStore } from "pinia";

export const useMenu = defineStore({
  id: "menu",
  state: () => ({
    // 应用列表
    menus: undefined,
    // 当前应用菜单
    currentMenus: undefined,
    // 选中的菜单
    selectedKeys: undefined,
    // 当前菜单
    currentMenuKey: undefined,
    // 是否显示切换应用
    showApps: false,
    // 当前应用
    currentAppId: undefined,
  }),
  getters: {
    getSelectedKeys() {
      return Number(this.selectedKeys);
    },
    getCurrentAppId() {
      const currentAppId = Number(this.currentAppId);
      return this.getCurrentMenus !== undefined ? currentAppId : undefined;
    },
    getCurrentMenus() {
      const currentAppId = Number(this.currentAppId);
      for (let index in this.menus) {
        let { id, children } = this.menus[index];
        if (currentAppId == id) {
          this.currentMenus = children;
          return children;
        }
      }
      return undefined;
    },
  },
  actions: {
    /**
     * 获取菜单
     * @param {*} val id|node
     * @param {*} menus
     * @returns
     */
    getMenu(val, menus = {}) {
      if (Object.keys(menus).length == 0) {
        menus = this.menus;
      }
      for (let i in menus) {
        if (menus[i].id == val || menus[i].node == val) {
          return menus[i];
        }
        if (menus[i].children) {
          let res = this.getMenu(val, menus[i].children);
          if (res !== undefined) {
            return res;
          }
        }
      }
    },
    setMenus(menus = {}) {
      this.menus = menus;
      if (this.currentAppId == undefined) {
        this.currentAppId = menus[0]["id"];
      }
    },
    setCurrentApp(currentAppId) {
      this.currentAppId = currentAppId;
    },
  },
  persist: true,
});
