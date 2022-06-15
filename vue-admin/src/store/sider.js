import { defineStore } from "pinia";

export const useSider = defineStore({
  id: "sider",
  state: () => ({
    // 侧边栏状态
    siderState: true,
  }),
  actions: {
    changeSider() {
      this.siderState = !this.siderState;
    },
  },
  persist: {
    key: "cc_sider",
    storage: window.localStorage,
  },
});
