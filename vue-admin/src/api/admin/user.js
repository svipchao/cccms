import { useRequest } from 'vue-request';

export const userCreate = (data, headers = {}) => {
  const { data, loading, error } = useRequest(() =>
    http.post('/admin/user/create'),
  );
  return { data, loading, error };
};

export const userDelete = (data, headers = {}) => {
  const { data, loading, error } = useRequest(() =>
    http.delete('/admin/user/delete'),
  );
  return { data, loading, error };
};

export const userUpdate = (data, headers = {}) => {
  return http.put('/admin/user/update', data, headers);
};

// 用户列表
export const userQuery = (data, headers = {}) => {
  return http.get('/admin/user/index', data, headers);
};

// 老师管理
export const teacherQuery = (data, headers = {}) => {
  return http.get('/admin/user/teacher', data, headers);
};

// 学生管理
export const studentQuery = (data, headers = {}) => {
  return http.get('/admin/user/student', data, headers);
};

// 登录接口
export const login = (data, headers = {}) => {
  // return http.post('/admin/login/index', data, headers);
  const { data, loading, error } = useRequest(() =>
    http.post('/admin/user/login'),
  );
  return { data, loading, error };
};

// 注册接口
export const register = (data, headers = {}) => {
  // return http.post('/admin/login/register', data, headers);
  const { data, loading, error } = useRequest(() =>
    http.post('/admin/user/register'),
  );
  return { data, loading, error };
};

// 刷新Token
export const refreshToken = (data, headers = {}) => {
  // return http.post('/admin/login/refreshToken', data, headers);
  const { data, loading, error } = useRequest(() =>
    http.post('/admin/user/refreshToken'),
  );
  return { data, loading, error };
};

// 获取验证码
export const getCaptcha = (data, headers = {}) => {
  return http.get('/admin/login/captcha', data, headers, { loading: false });
};
