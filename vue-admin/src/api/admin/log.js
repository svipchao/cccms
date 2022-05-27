import http from "@/utils/http.js";

// 删
export const logDelete = (data) => {
  return http.delete("/admin/log/delete", data);
};

// 查
export const logQuery = (data) => {
  return http.get("/admin/log/index", data);
};
