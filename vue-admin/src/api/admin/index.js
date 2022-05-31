import http from "@/utils/http.js";

// 查
export const indexQuery = (data) => {
  return http.get("/admin/index/index", data);
};
