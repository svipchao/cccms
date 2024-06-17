import http from '@/api/index.js';

// 增
export const fileCreate = (data, headers = {}) => {
  return http.post('/admin/file/create', data, headers);
};

// 删
export const fileDelete = (data, headers = {}) => {
  return http.delete('/admin/file/delete', data, headers);
};

// 改
export const fileUpdate = (data, headers = {}) => {
  return http.put('/admin/file/update', data, headers);
};

// 查
export const fileQuery = (data, headers = {}) => {
  return http.get('/admin/file/index', data, headers);
};
