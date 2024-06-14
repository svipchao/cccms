import { defineStore } from 'pinia';

export const useSystemStore = defineStore({
  id: 'system',
  state: () => ({
    isRegisterRouteFresh: true,
  }),
  actions: {
    setRegisterRouteFresh() {
      this.isRegisterRouteFresh = !this.isRegisterRouteFresh;
    },
  },
});
