import http from "@/utils/http.js";

// 增
export const groupCreate = (data) => {
  return http.post("/admin/group/create", data);
};

// 删
export const groupDelete = (data) => {
  return http.delete("/admin/group/delete", data);
};

// 改
export const groupUpdate = (data) => {
  return http.put("/admin/group/update", data);
};

// 查
export const groupQuery = (data) => {
  return http.get("/admin/group/index", data);
};
