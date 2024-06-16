import http from '@/api/index.js';

// 增
export const configCreate = (data, headers = {}) => {
  return http.post('/admin/config/create', data, headers);
};

// 删
export const configDelete = (data, headers = {}) => {
  return http.delete('/admin/config/delete', data, headers);
};

// 改
export const configUpdate = (data, headers = {}) => {
  return http.put('/admin/config/update', data, headers);
};

// 查
export const configQuery = (data, headers = {}) => {
  return http.get('/admin/config/index', data, headers);
};
