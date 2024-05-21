import { defineStore } from 'pinia';

export const useMenuStore = defineStore('menu', {
  state() {
    return { count: 0 };
  },
  actions: {
    inc() {
      this.count++;
    },
  },
});
