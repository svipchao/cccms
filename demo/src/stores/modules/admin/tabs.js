import { defineStore } from 'pinia';

export const useTabsStore = defineStore('tabs', {
  state() {
    return { count: 0 };
  },
  actions: {
    inc() {
      this.count++;
    },
  },
});
