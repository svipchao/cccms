import axios from 'axios';
import config from '@/config';
import router from '@/router/index';
import { message } from 'ant-design-vue';
import { useUser } from '@/store/admin/user.js';

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
      code_message_show: false, // 是否开启code不为200时的信息提示, 默认为false
    },
    customOptions
  );

  // 请求拦截
  service.interceptors.request.use(
    (config) => {
      removePending(config);
      custom_options.repeat_request_cancel && addPending(config);
      // 创建loading实例
      if (custom_options.loading) {
        LoadingInstance._count++;
        if (LoadingInstance._count === 1) {
          LoadingInstance._target = message.loading(loadingOptions, 0);
        }
      }
      // 自动携带token
      const userStore = useUser();
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
        response.data.code !== 200
      ) {
        message.error(response.data.msg);
        return Promise.reject(response.data); // code不等于200, 页面具体逻辑就不执行了
      }
      return custom_options.reduct_data_format ? response.data : response;
    },
    (error) => {
      error.config && removePending(error.config);
      custom_options.loading && closeLoading(custom_options); // 关闭loading
      custom_options.error_message_show && httpErrorStatusHandle(error); // 处理错误状态码
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
  let messageBody = {};
  if (error && error.response) {
    switch (error.response.status) {
      case 302:
        messageBody.content = '接口重定向了！';
        break;
      case 400:
        messageBody.content = '参数不正确！';
        break;
      case 401:
        if (router.currentRoute.value.path !== '/login') {
          messageBody = {
            content: '您未登录，或者登录已经超时，请先登录！',
            onClose: () => {
              const userStore = useUser();
              userStore.logout();
            },
          };
        } else {
          messageBody.content = error.response.data.msg;
        }
        break;
      case 404:
        messageBody.content = `请求地址出错: ${error.response.config.url}`;
        break;
      case 408:
        messageBody.content = '请求超时！';
        break;
      case 409:
        messageBody.content = '系统已存在相同数据！';
        break;
      case 500:
        messageBody.content = '服务器内部错误！';
        break;
      case 501:
        messageBody.content = '服务未实现！';
        break;
      case 502:
        messageBody.content = '网关错误！';
        break;
      case 503:
        messageBody.content = '服务不可用！';
        break;
      case 504:
        messageBody.content = '服务暂时无法访问，请稍后再试！';
        break;
      case 505:
        messageBody.content = 'HTTP版本不受支持！';
        break;
      default:
        messageBody.content =
          error.response.data.msg || '异常问题，请联系管理员！';
        break;
    }
  }
  if (error.message.includes('timeout')) {
    messageBody.content = '网络请求超时！';
  }
  if (error.message.includes('Network')) {
    messageBody.content = window.navigator.onLine
      ? '服务端异常！'
      : '您断网了！';
  }
  message.error(messageBody);
}

/**
 * 关闭Loading层实例
 * @param {*} _options
 */
function closeLoading(_options) {
  if (_options.loading && LoadingInstance._count > 0) LoadingInstance._count--;
  if (LoadingInstance._count === 0) {
    setTimeout(LoadingInstance._target, 500);
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
