import { defineStore } from 'pinia';

export const useThemeStore = defineStore('theme', {
  state: () => ({
    darkTheme: false,
    showSider: true,
    contentFullscreen: false,
  }),
  getters: {
    getTheme() {
      if (this.darkTheme) {
        document.body.setAttribute('arco-theme', 'dark');
      } else {
        document.body.removeAttribute('arco-theme');
      }
      return this.darkTheme;
    },
    getShowSider() {
      return !this.contentFullscreen && this.showSider;
    },
  },
  actions: {
    switchTheme() {
      this.darkTheme = !this.darkTheme;
    },
    switchShowSider() {
      this.showSider = !this.showSider;
    },
    switchContentFullscreen() {
      this.contentFullscreen = !this.contentFullscreen;
    },
  },
  persist: true,
});
