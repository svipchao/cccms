import http from '@/api/index.js';

// 增
export const menuCreate = (data, headers = {}) => {
  return http.post('/admin/menu/create', data, headers);
};

// 删
export const menuDelete = (data, headers = {}) => {
  return http.delete('/admin/menu/delete', data, headers);
};

// 改
export const menuUpdate = (data, headers = {}) => {
  return http.put('/admin/menu/update', data, headers);
};

// 查
export const menuQuery = (data, headers = {}) => {
  return http.get('/admin/menu/index', data, headers);
};
