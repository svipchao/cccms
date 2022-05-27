import http from "@/utils/http.js";

// 增
export const routeCreate = (data) => {
  return http.post("/admin/route/create", data);
};

// 删
export const routeDelete = (data) => {
  return http.delete("/admin/route/delete", data);
};

// 改
export const routeUpdate = (data) => {
  return http.put("/admin/route/update", data);
};

// 查
export const routeQuery = (data) => {
  return http.get("/admin/route/index", data);
};
