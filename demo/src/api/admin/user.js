import http from "@/api/index.js";

// 登录接口
export const login = (data, headers = {}) => {
  return http.post("/admin/login/index", data, headers);
};

// 注册接口
export const register = (data, headers = {}) => {
  return http.post("/admin/login/register", data, headers);
};

// 刷新Token
export const refreshToken = (data, headers = {}) => {
  return http.post("/admin/login/refreshToken", data, headers);
};

// 获取验证码
export const getCaptcha = (data, headers = {}) => {
  return http.get("/admin/login/captcha", data, headers, { loading: false });
};

export const userCreate = (data, headers = {}) => {
  return http.post('/admin/user/create', data, headers);
};

export const userDelete = (data, headers = {}) => {
  return http.delete('/admin/user/delete', data, headers);
};

export const userUpdate = (data, headers = {}) => {
  return http.put('/admin/user/update', data, headers);
};

// 用户列表
export const userQuery = (data, headers = {}) => {
  return http.get('/admin/user/index', data, headers);
};
