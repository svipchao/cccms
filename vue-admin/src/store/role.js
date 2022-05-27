import { defineStore } from "pinia";

export const useRole = defineStore({
  id: "role",
  state: () => ({
    // ID
    id: undefined,
    // 角色名称
    role_name: undefined,
    // 角色备注
    role_desc: undefined,
    // 节点列表
    nodes: [],
  }),
  getters: {
    getNodes(state) {
      return state.nodes;
    },
  },
  actions: {
    setRole(roleInfo) {
      this.$patch(roleInfo);
    },
  },
  persist: {
    key: "role",
    storage: window.localStorage,
  },
});
