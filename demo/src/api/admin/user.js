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
