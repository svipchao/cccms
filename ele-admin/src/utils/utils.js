import { useRouter } from "vue-router";

export const isLink = (url) => {
  return /^(http|ftp|https):\/\//g.test(url);
};

export const getQueryParams = (key) => {
  const { currentRoute } = useRouter();
  return currentRoute.value.query[key] || false;
};

/**
 * 深度克隆对象
 * @param obj 源对象
 * @return 克隆后的对象
 */
export const deepClone = (obj) => {
  let result;
  if (Array.isArray(obj)) {
    result = [];
  } else if (typeof obj === "object") {
    result = {};
  } else {
    return obj;
  }
  Object.keys(obj).forEach((key) => {
    if (typeof obj[key] === "object") {
      result[key] = deepClone(obj[key]);
    } else {
      result[key] = obj[key];
    }
  });
  return result;
};

/**
 * 赋值不改变字段原字段
 * @param target 目标对象
 * @param source 源对象
 */
export const assignObject = (target, source) => {
  // 遍历同时进行复制
  for (var key in source) {
    // 1.获取source中的所有的属性的值
    var item = source[key];
    // 2.判断这个值的类型是不是数组 - 遍历
    if (item instanceof Array) {
      // 过滤数组
      // 开辟数组空间
      target[key] = [];
      // 调用这个函数，把source中的数组的属性一个个的赋值到target对象的数组中的
      target[key] = assignObject(item, target[key]); // item是数组类型
    } else if (item instanceof Object) {
      // 过滤对象类型
      // 开辟一个对象类型的空间
      target[key] = {};
      // 调用这个函数进行遍历
      target[key] = assignObject(item, target[key]); // item是对象类型
    } else {
      // 普通类型
      target[key] = item; // item是属性
    }
  }
  return target;
};
