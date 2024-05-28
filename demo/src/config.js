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
  baseUrl: 'http://cms.qq.com',
  // 全局确认框加载时间 ms
  okLoadingTime: 1000,
};

if (import.meta.env.MODE === 'development') {
  // 开发模式
  config.baseUrl = 'http://cms.qq.com';
} else {
  // 生产模式
  config.baseUrl = 'http://cms.qq.com';
}

export default config;
