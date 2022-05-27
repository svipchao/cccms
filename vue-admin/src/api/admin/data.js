import http from "@/utils/http.js";

// 增
export const dataCreate = (data) => {
  return http.post("/admin/data/create", data);
};

// 删
export const dataDelete = (data) => {
  return http.delete("/admin/data/delete", data);
};

// 改
export const dataUpdate = (data) => {
  return http.put("/admin/data/update", data);
};

// 查
export const dataQuery = (data) => {
  return http.get("/admin/data/index", data);
};
