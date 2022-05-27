import http from "@/utils/http.js";

// 增
export const typesCreate = (data) => {
  return http.post("/admin/types/create", data);
};

// 删
export const typesDelete = (data) => {
  return http.delete("/admin/types/delete", data);
};

// 改
export const typesUpdate = (data) => {
  return http.put("/admin/types/update", data);
};

// 查
export const typesQuery = (data) => {
  return http.get("/admin/types/index", data);
};
