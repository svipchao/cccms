import http from "@/utils/http.js";

// 增
export const userCreate = (data) => {
  return http.post("/admin/user/create", data);
};

// 删
export const userDelete = (data) => {
  return http.delete("/admin/user/delete", data);
};

// 改
export const userUpdate = (data) => {
  return http.put("/admin/user/update", data);
};

// 查
export const userQuery = (data) => {
  return http.get("/admin/user/index", data);
};

// 登录接口
export const login = (data, headers = {}) => {
  return http.post("/admin/user/login", data, headers, { loading: false });
};
