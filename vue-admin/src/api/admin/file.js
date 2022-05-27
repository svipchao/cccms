import http from "@/utils/http.js";

// 增
export const fileCreate = (data) => {
  return http.post("/admin/file/create", data);
};

// 删
export const fileDelete = (data) => {
  return http.delete("/admin/file/delete", data);
};

// 改
export const fileUpdate = (data) => {
  return http.put("/admin/file/update", data);
};

// 查
export const fileQuery = (data) => {
  return http.get("/admin/file/index", data);
};
