import http from "@/utils/http.js";

// 增
export const roleCreate = (data) => {
  return http.post("/admin/role/create", data);
};

// 删
export const roleDelete = (data) => {
  return http.delete("/admin/role/delete", data);
};

// 改
export const roleUpdate = (data) => {
  return http.put("/admin/role/update", data);
};

// 查
export const roleQuery = (data) => {
  return http.get("/admin/role/index", data);
};

// 角色权限
export const authQuery = (data) => {
  return http.get("/admin/role/auth", data);
};
