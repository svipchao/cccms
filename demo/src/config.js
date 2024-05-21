let config = {
  // 项目名称
  name: 'CCCMS',
  // 接口URL
  baseUrl: 'http://rpa.qq.com',
  // 全局确认框加载时间 ms
  okLoadingTime: 1000,
};

if (import.meta.env.MODE === 'development') {
  // 开发模式
  config.baseUrl = 'http://rpa.qq.com';
  // config.baseUrl = 'https://demo.weilianyiyao.top';
} else {
  // 生产模式
  config.baseUrl = 'https://demo.weilianyiyao.top';
}

export default config;
