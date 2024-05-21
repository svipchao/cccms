import { defineStore } from 'pinia';

export const useUserStore = defineStore('user', {
  state() {
    return { count: 0 };
  },
  actions: {
    inc() {
      this.count++;
    },
  },
  persist: true,
});
