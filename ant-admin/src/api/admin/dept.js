import http from '@/api/index.js';

export const deptCreate = (data, headers = {}) => {
  return http.post('/admin/dept/create', data, headers);
};

export const deptDelete = (data, headers = {}) => {
  return http.delete('/admin/dept/delete', data, headers);
};

export const deptUpdate = (data, headers = {}) => {
  return http.put('/admin/dept/update', data, headers);
};

export const deptQuery = (data, headers = {}) => {
  return http.get('/admin/dept/index', data, headers);
};
