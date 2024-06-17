import http from '@/api/index.js';

// 删
export const logDelete = (data, headers = {}) => {
  return http.delete('/admin/log/delete', data, headers);
};

// 查
export const logQuery = (data, headers = {}) => {
  return http.get('/admin/log/index', data, headers);
};

// 查
export const logInfoQuery = (data, headers = {}) => {
  return http.get('/admin/log/info', data, headers);
};
