import { ref, computed } from "vue";
import { useRouter } from "vue-router";

// 因为是后端分页，所以需要设定好后端分页参数，这里根据业务需求，默认为page、limit，分别代表查询页和每页数据数量参数，current、pageSize分别代表对于的值。
export default function paginationFun(current = 1, pageSize = 10) {
  // 当前页码
  const page = ref(current);
  // 每页数量
  const limit = ref(pageSize);
  // 数据总数
  const total = ref(0);

  const pageChange = (current, pageSize) => {
    page.value = current;
    limit.value = pageSize;
  };

  const setTotal = (totalSize) => {
    total.value = totalSize;
  };

  const pagination = computed(() => {
    return {
      total: total.value,
      current: page.value,
      pageSize: limit.value,
      defaultPageSize: limit.value,
      pageSizeOptions: ["15", "30", "50", "100", "300"],
      simple: window.innerWidth < 930,
      size: "default",
      onChange: (current, pageSize) => pageChange(current, pageSize),
      onShowSizeChange: (current, pageSize) => pageChange(current, pageSize),
    };
  });

  // 检测页码和每页数量是否更改
  const isUpdateState = computed(() => {
    return page.value + limit.value;
  });

  return {
    pagination,
    isUpdateState,
    setTotal,
  };
}

/**
 * 表格字段处理
 * @param {*} columns 表格列表信息
 *                      [{title: "角色名称", dataIndex: "role_name"},{}...]
 *                      dataIndex必须存在
 * @param {*} fields 字段信息
 *                      [id,role_name]
 * @param {*} ignoreField 忽略的字段
 *                      [role_id,role_name]
 */
export const tableField = (columns, fields = [], ignoreField = []) => {
  const datas = [];
  if (typeof fields === "object") {
    fields = Object.values(fields);
  }
  for (const i in columns) {
    // 判断是否隐藏字段 判断字段信息内是否包含field
    if (
      columns[i].dataIndex === undefined ||
      fields.indexOf(columns[i].dataIndex) !== -1 ||
      ignoreField.indexOf(columns[i].dataIndex) !== -1
    ) {
      datas.push(columns[i]);
    }
  }
  return datas;
};

export const isLink = (url) => {
  return /^(http|ftp|https):\/\//g.test(url);
};

/*
 * 时间戳转换成指定格式日期
 * dateFormat(11111111111111, 'Y年m月d日 H时i分')
 */
export const timestamp = (time, format = "yyyy-MM-dd HH:mm:ss") => {
  time = time || Date.parse(new Date()) / 1000;

  if (typeof time === "number" && String(time).length === 10) {
    time = time * 1e3;
  }
  const digit = function (value, length = 2) {
    if (typeof value === "undefined" || value === null) {
      return "";
    }
    if (String(value).length >= length) {
      return String(value);
    }
    return (Array(length).join("0") + value).slice(-length);
  };
  const date = new Date(time);
  const ymd = [digit(date.getFullYear(), 4), digit(date.getMonth() + 1), digit(date.getDate())];
  const hms = [digit(date.getHours()), digit(date.getMinutes()), digit(date.getSeconds())];
  return format
    .replace(/yyyy/g, ymd[0])
    .replace(/MM/g, ymd[1])
    .replace(/dd/g, ymd[2])
    .replace(/HH/g, hms[0])
    .replace(/mm/g, hms[1])
    .replace(/ss/g, hms[2]);
};

/*
 * 格式日期转换成时间戳
 */
export const toTimestamp = (format = "") => {
  return new Date(format).getTime() / 1000;
};

// 月份天数
export const getMonthDays = () => {
  var date = new Date();
  var year = date.getFullYear();
  var month = date.getMonth() + 1;
  var d = new Date(year, month, 0);
  return d.getDate();
};

/**
 * 时间语义化
 * @param time 时间
 * @param onlyDate 超过30天是否仅返回日期
 */
export const timeAgo = (time, onlyDate) => {
  if (!time) {
    return "";
  }
  const arr = [[], []];
  const stamp = new Date().getTime() - new Date(time).getTime();
  if (stamp < 0 || stamp > 1e3 * 60 * 60 * 24 * 31) {
    const date = new Date(time);
    arr[0][0] = digit(date.getFullYear(), 4);
    arr[0][1] = digit(date.getMonth() + 1);
    arr[0][2] = digit(date.getDate());
    if (!onlyDate) {
      arr[1][0] = digit(date.getHours());
      arr[1][1] = digit(date.getMinutes());
      arr[1][2] = digit(date.getSeconds());
    }
    return arr[0].join("-") + " " + arr[1].join(":");
  }
  if (stamp >= 1e3 * 60 * 60 * 24) {
    return ((stamp / 1e3 / 60 / 60 / 24) | 0) + "\u5929\u524D";
  }
  if (stamp >= 1e3 * 60 * 60) {
    return ((stamp / 1e3 / 60 / 60) | 0) + "\u5C0F\u65F6\u524D";
  }
  if (stamp >= 1e3 * 60 * 3) {
    return ((stamp / 1e3 / 60) | 0) + "\u5206\u949F\u524D";
  }
  return "\u521A\u521A";
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

/**
 * 生成随机字符串
 * @param length 长度
 * @param radix 基数
 */
export const uuid = (length = 32, radix) => {
  const num = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
  let result = "";
  for (let i = 0; i < length; i++) {
    result += num.charAt(Math.floor(Math.random() * (radix || num.length)));
  }
  return result;
};

/**
 * 生成m到n的随机数
 * @param m 最小值, 包含
 * @param n 最大值, 不包含
 */
export const random = (m, n) => {
  return Math.floor(Math.random() * (m - n) + n);
};

/**
 * 数字千分位
 * @param num 数字
 */
export const formatNumber = (num) => {
  return String(num != null ? num : "").replace(/(\d{1,3})(?=(\d{3})+(?:$|\.))/g, "$1,");
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

// 随机颜色
export const randColor = () => {
  const colors = [
    "rgb(24, 144, 255)",
    "rgb(102, 181, 255)",
    "rgb(65, 217, 199)",
    "rgb(47, 194, 91)",
    "rgb(110, 219, 143)",
    "rgb(154, 230, 92)",
    "rgb(250, 204, 20)",
    "rgb(230, 150, 92)",
    "rgb(87, 173, 113)",
    "rgb(34, 50, 115)",
    "rgb(115, 138, 230)",
    "rgb(117, 100, 204)",
    "rgb(133, 67, 224)",
    "rgb(168, 119, 237)",
    "rgb(92, 142, 230)",
    "rgb(19, 194, 194)",
    "rgb(112, 224, 224)",
    "rgb(92, 163, 230)",
    "rgb(52, 54, 199)",
    "rgb(128, 130, 255)",
    "rgb(221, 129, 230)",
    "rgb(240, 72, 100)",
    "rgb(250, 125, 146)",
    "rgb(213, 152, 217)",
  ];
  return colors[Math.floor(Math.random() * (0 - colors.length) + colors.length)];
};

// 字符串转换
export const praseStrEmpty = (str) => {
  if (!str || str == "undefined" || str == "null") {
    return "";
  }
  return str;
};

// 字符串提取数字
export const extractInt = (str) => {
  return str.replace(/[^0-9]/gi, "");
};

export const getQueryParams = (key) => {
  const { currentRoute } = useRouter();
  return currentRoute.value.query[key] || false;
};

// 复制字符串到剪切板
export const copyText = (text, success, error) => {
  let successful;
  success =
    success ||
    function () {
      console.log("执行success！！！！！");
    };
  error =
    error ||
    function () {
      console.log("执行error！！！！！");
    };
  // 如果是IE，就使用IE专有方式进行拷贝
  // 好处是可以直接复制而不用曲线救国，创建textarea来实现。
  if (window.clipboardData) {
    successful = window.clipboardData.setData("Text", text);
    if (successful) {
      success();
    } else {
      error();
    }
  } else {
    const textArea = document.createElement("textarea");
    const styleArr = [
      "position: fixed;",
      "top: 0;",
      "left: 0;",
      "padding: 0;",
      // 针对safari10
      // 增大textarea的大小，否则的话在safari10中successful为true，
      // 但却什么也没拷贝
      "width: 31px;",
      "height: 21px;",
      "border: none;",
      "outline: none;",
      "boxShadow: none;",
      "background: transparent;",
      // 针对safari10
      // 因为增大了textarea的大小，故使用其他技巧隐藏之
      "opacity: 0;",
      "z-index: -1;",
    ];
    textArea.style.cssText = styleArr.join("");
    textArea.value = text;
    document.body.appendChild(textArea);
    textArea.select();
    try {
      successful = document.execCommand("copy");
      const msg = successful ? "successful" : "unsuccessful";
      console.log("Copying text command was " + msg);
      try {
        if (successful) {
          success();
        } else {
          error();
        }
      } catch (e) {
        console.log("执行success或error有异常！！！！！");
        console.error(e);
      }
    } catch (e) {
      console.log("Oops, unable to copy");
      error();
    }
    // 卸磨杀驴
    document.body.removeChild(textArea);
  }
};

// 多次点击事件
export const nClick = (callback, n = 2) => {
  let timer;
  if (timer) {
    clearTimeout(timer);
  }
  callback =
    callback ||
    function () {
      console.log("执行回调");
    };
  if (event.detail == n) {
    callback();
  }
};
