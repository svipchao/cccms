import http from "@/utils/http.js";

// 增
export const menuCreate = (data) => {
  return http.post("/admin/menu/create", data);
};

// 删
export const menuDelete = (data) => {
  return http.delete("/admin/menu/delete", data);
};

// 改
export const menuUpdate = (data) => {
  return http.put("/admin/menu/update", data);
};

// 查
export const menuQuery = (data) => {
  return http.get("/admin/menu/index", data);
};
