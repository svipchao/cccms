import axios from 'axios';
import config from '@/config';
import router from '@/router/index';
import NProgress from 'nprogress';
import { Message } from '@arco-design/web-vue';
import { useUserStore } from '@/stores/admin/user.js';

const pendingMap = new Map();

const LoadingInstance = {
  _target: null,
  _count: 0,
};

function http(
  axiosConfig,
  customOptions,
  loadingOptions = { content: '请稍等...' }
) {
  const service = axios.create({
    baseURL: config.baseUrl, // 设置统一的请求前缀
    timeout: 100000, // 设置统一的超时时长
    params: {
      encode: 'json',
    },
  });

  // 自定义配置
  let custom_options = Object.assign(
    {
      repeat_request_cancel: true, // 是否开启取消重复请求, 默认为 true
      loading: true, // 是否开启loading层效果, 默认为false
      reduct_data_format: true, // 是否开启简洁的数据结构响应, 默认为true
      error_message_show: true, // 是否开启接口错误信息展示,默认为true
      code_message_show: true, // 是否开启code不为2xx时的信息提示, 默认为false
    },
    customOptions
  );

  // 请求拦截
  service.interceptors.request.use(
    (config) => {
      NProgress.configure({ minimum: 0.01 });
      NProgress.start();
      removePending(config);
      custom_options.repeat_request_cancel && addPending(config);
      // 创建loading实例
      if (custom_options.loading) {
        LoadingInstance._count++;
        if (LoadingInstance._count === 1) {
          LoadingInstance._target = Message.loading(loadingOptions);
        }
      }
      // 自动携带token
      const userStore = useUserStore();
      if (userStore.accessToken) {
        config.headers.accessToken = userStore.accessToken;
      }
      if (typeof config.headers.accessToken === 'undefined') {
        delete config.headers.accessToken;
      }
      return config;
    },
    (error) => {
      return Promise.reject(error);
    }
  );

  // 响应拦截
  service.interceptors.response.use(
    (response) => {
      removePending(response.config);
      custom_options.loading && closeLoading(custom_options); // 关闭loading
      if (
        custom_options.code_message_show &&
        response.data &&
        (response.data.code >= 300 || response.data.code < 200)
      ) {
        Message.error(response.data.msg);
        return Promise.reject(response.data); // code不等于200, 页面具体逻辑就不执行了
      }
      if (NProgress.isStarted()) {
        setTimeout(() => {
          NProgress.done(true);
        }, 100);
      }
      return custom_options.reduct_data_format ? response.data : response;
    },
    (error) => {
      error.config && removePending(error.config);
      custom_options.loading && closeLoading(custom_options); // 关闭loading
      custom_options.error_message_show && httpErrorStatusHandle(error); // 处理错误状态码
      if (NProgress.isStarted()) {
        setTimeout(() => {
          NProgress.done(true);
        }, 100);
      }
      return Promise.reject(error); // 错误继续返回给到具体页面
    }
  );
  return service(axiosConfig);
}

export default http;

/**
 * 处理异常
 * @param {*} error
 */
function httpErrorStatusHandle(error) {
  // 处理被取消的请求
  if (axios.isCancel(error)) {
    return console.error('请求的重复请求：' + error.message);
  }
  let message = {};
  if (error && error.response) {
    switch (error.response.status) {
      case 302:
        message.content = '接口重定向了！';
        break;
      case 400:
        message.content = '参数不正确！';
        break;
      case 401:
        if (router.currentRoute.value.path !== '/login') {
          message = {
            content: '您未登录，或者登录已经超时，请先登录！',
            onClose: () => {
              const userStore = useUserStore();
              userStore.logout(false);
            },
          };
        } else {
          message.content = error.response.data.msg;
        }
        break;
      case 404:
        message.content = `请求地址出错: ${error.response.config.url}`;
        break;
      case 408:
        message.content = '请求超时！';
        break;
      case 409:
        message.content = '系统已存在相同数据！';
        break;
      case 500:
        message.content = '服务器内部错误！';
        break;
      case 501:
        message.content = '服务未实现！';
        break;
      case 502:
        message.content = '网关错误！';
        break;
      case 503:
        message.content = '服务不可用！';
        break;
      case 504:
        message.content = '服务暂时无法访问，请稍后再试！';
        break;
      case 505:
        message.content = 'HTTP版本不受支持！';
        break;
      default:
        message.content = error.response.data.msg || '异常问题，请联系管理员！';
        break;
    }
  }
  if (error.message.includes('timeout')) {
    message.content = '网络请求超时！';
  }
  if (error.message.includes('Network')) {
    message.content = window.navigator.onLine ? '服务端异常！' : '您断网了！';
  }
  Message.error(message);
}

/**
 * 关闭Loading层实例
 * @param {*} _options
 */
function closeLoading(_options) {
  if (_options.loading && LoadingInstance._count > 0) LoadingInstance._count--;
  if (LoadingInstance._count === 0) {
    LoadingInstance._target.close();
    LoadingInstance._target = null;
  }
}

/**
 * 储存每个请求的唯一cancel回调, 以此为标识
 * @param {*} config
 */
function addPending(config) {
  const pendingKey = getPendingKey(config);
  config.cancelToken =
    config.cancelToken ||
    new axios.CancelToken((cancel) => {
      if (!pendingMap.has(pendingKey)) {
        pendingMap.set(pendingKey, cancel);
      }
    });
}

/**
 * 删除重复的请求
 * @param {*} config
 */
function removePending(config) {
  const pendingKey = getPendingKey(config);
  if (pendingMap.has(pendingKey)) {
    const cancelToken = pendingMap.get(pendingKey);
    // 如你不明白此处为什么需要传递pendingKey可以看文章下方的补丁解释
    cancelToken(pendingKey);
    pendingMap.delete(pendingKey);
  }
}

/**
 * 生成唯一的每个请求的唯一key
 * @param {*} config
 * @returns
 */
function getPendingKey(config) {
  let { url, method, params, data } = config;
  if (typeof data === 'string') {
    // response里面返回的config.data是个字符串对象
    data = JSON.parse(data);
  }
  return [url, method, JSON.stringify(params), JSON.stringify(data)].join('&');
}
