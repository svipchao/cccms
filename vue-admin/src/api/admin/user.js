import http from '@/api/index.js';

// 登录接口
export const login = (data, headers = {}) => {
  return http.post('/admin/user/login', data, headers);
};

// 注册接口
export const register = (data, headers = {}) => {
  return http.post('/admin/user/register', data, headers);
};

// 刷新Token
export const refreshToken = (data, headers = {}) => {
  return http.post('/admin/user/refreshToken', data, headers);
};

// 获取验证码
export const getCaptcha = (data, headers = {}) => {
  return http.get('/admin/user/captcha', data, headers, { loading: false });
};

// 新增用户
export const userCreate = (data, headers = {}) => {
  return http.post('/admin/user/create', data, headers);
};

// 删除用户
export const userDelete = (data, headers = {}) => {
  return http.delete('/admin/user/delete', data, headers);
};

// 更新用户
export const userUpdate = (data, headers = {}) => {
  return http.put('/admin/user/update', data, headers);
};

// 用户列表
export const userQuery = (data, headers = {}) => {
  return http.get('/admin/user/index', data, headers);
};
