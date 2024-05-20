let config = {
  // 标题
  title: 'CCCMS',
  // 描述
  description: 'CCCMS后台管理系统',
  // 版权链接
  copyrightTitle: 'CCCMS',
  // 版权链接
  copyrightUrl: 'javascript:;',
  // 接口URL
  baseUrl: 'http://rpa.qq.com',
  // 全局确认框加载时间 ms
  okLoadingTime: 1000,
  // 全局确认框加载时间 ms
  ossImageUrl: 'http://zz-media.weilianyiyao.top/',
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
