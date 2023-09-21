import http from '@/utils/http/http.js';

// 登录接口
export const login = (data, headers = {}) => {
  return http.post('/admin/login/index', data, headers);
};

// 刷新Token
export const refreshToken = (data, headers = {}) => {
  return http.post('/admin/login/refreshToken', data, headers);
};

// 刷新Token
export const getCaptcha = (data, headers = {}) => {
  return http.get('/admin/login/captcha', data, headers, { loading: false });
};
