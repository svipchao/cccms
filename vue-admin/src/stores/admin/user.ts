import { defineStore } from 'pinia';

export default defineStore('user', {
  state() {
    return {
      id: 0, // ID
      nickname: '', // 昵称
      username: '', // 用户名
      phone: '', // 手机号
      email: '', // 邮箱
      home_url: '', // 主页
      accessToken: '', // accessToken
      login_expire: 0, // 过期时间戳
      nodes: [], // 权限节点列表
      isRegisterRouteFresh: true, // 是否注册路由
      configs: [], // 系统配置
    };
  },
  getters: {
    getFirstNickname(): string {
      return this.nickname.substr(0, 1);
    },
  },
  actions: {
    setUserInfo() {},
    setToken() {},
    logout() {},
  },
  persist: true,
});
