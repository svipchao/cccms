import { defineStore } from "pinia";

export const useSystem = defineStore({
  id: "system",
  state: () => ({
    isRegisterRouteFresh: true,
  }),
  actions: {
    setRegisterRouteFresh() {
      this.isRegisterRouteFresh = !this.isRegisterRouteFresh;
    },
  },
});
