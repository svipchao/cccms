import http from '@/api/index.js';

// 增
export const configCreate = (data) => {
  return http.post('/admin/config/create', data);
};

// 删
export const configDelete = (data) => {
  return http.delete('/admin/config/delete', data);
};

// 改
export const configUpdate = (data) => {
  return http.put('/admin/config/update', data);
};

// 查
export const configQuery = (data) => {
  return http.get('/admin/config/index', data);
};
