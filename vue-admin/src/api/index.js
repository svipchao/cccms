import http from "@/api/axios";

//封装获取数据
export default {
  post(url, data, headers, customOptions, loadingOptions) {
    return http(
      {
        url: url,
        method: "post",
        data: data,
        headers: headers,
      },
      customOptions,
      loadingOptions
    );
  },
  put(url, data, headers, customOptions, loadingOptions) {
    return http(
      {
        url: url,
        method: "put",
        data: data,
        headers: headers,
      },
      customOptions,
      loadingOptions
    );
  },
  delete(url, data, headers, customOptions, loadingOptions) {
    return http(
      {
        url: url,
        method: "delete",
        data: data,
        headers: headers,
      },
      customOptions,
      loadingOptions
    );
  },
  get(url, params, customOptions, loadingOptions) {
    return http(
      {
        url: url,
        method: "get",
        params: params,
      },
      customOptions,
      loadingOptions
    );
  },
};
