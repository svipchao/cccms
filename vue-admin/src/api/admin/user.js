import http from "@/api/index.js";

export const userCreate = (data, headers = {}) => {
  return http.post("/admin/user/create", data, headers);
};

export const userDelete = (data, headers = {}) => {
  return http.delete("/admin/user/delete", data, headers);
};

export const userUpdate = (data, headers = {}) => {
  return http.put("/admin/user/update", data, headers);
};

export const userQuery = (data, headers = {}) => {
  return http.get("/admin/user/index", data, headers);
};
