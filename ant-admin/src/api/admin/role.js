import http from '@/utils/http/http.js';

export const roleCreate = (data, headers = {}) => {
  return http.post('/admin/role/create', data, headers);
};

export const roleDelete = (data, headers = {}) => {
  return http.delete('/admin/role/delete', data, headers);
};

export const roleUpdate = (data, headers = {}) => {
  return http.put('/admin/role/update', data, headers);
};

export const authQuery = (data, headers = {}) => {
  return http.get('/admin/role/auth', data, headers);
};

export const roleQuery = (data, headers = {}) => {
  return http.get('/admin/role/index', data, headers);
};
