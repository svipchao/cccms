/**
 * 时间戳转换成指定格式日期
 * dateFormat(11111111111111, 'Y年m月d日 H时i分')
 * @param {*} time
 * @param {*} format
 * @returns
 */
export const timestamp = (time, format = 'yyyy-MM-dd HH:mm:ss') => {
  time = time || Date.parse(new Date()) / 1000;

  if (typeof time === 'number' && String(time).length === 10) {
    time = time * 1e3;
  }
  const digit = function (value, length = 2) {
    if (typeof value === 'undefined' || value === null) {
      return '';
    }
    if (String(value).length >= length) {
      return String(value);
    }
    return (Array(length).join('0') + value).slice(-length);
  };
  const date = new Date(time);
  const ymd = [
    digit(date.getFullYear(), 4),
    digit(date.getMonth() + 1),
    digit(date.getDate()),
  ];
  const hms = [
    digit(date.getHours()),
    digit(date.getMinutes()),
    digit(date.getSeconds()),
  ];
  return format
    .replace(/yyyy/g, ymd[0])
    .replace(/MM/g, ymd[1])
    .replace(/dd/g, ymd[2])
    .replace(/HH/g, hms[0])
    .replace(/mm/g, hms[1])
    .replace(/ss/g, hms[2]);
};

/**
 * 格式日期转换成时间戳
 * @param {*} format
 * @returns
 */
export const toTimestamp = (format = '') => {
  return new Date(format).getTime() / 1000;
};

/**
 * 月份天数
 * @returns
 */
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
    return '';
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
    return arr[0].join('-') + ' ' + arr[1].join(':');
  }
  if (stamp >= 1e3 * 60 * 60 * 24) {
    return ((stamp / 1e3 / 60 / 60 / 24) | 0) + '\u5929\u524D';
  }
  if (stamp >= 1e3 * 60 * 60) {
    return ((stamp / 1e3 / 60 / 60) | 0) + '\u5C0F\u65F6\u524D';
  }
  if (stamp >= 1e3 * 60 * 3) {
    return ((stamp / 1e3 / 60) | 0) + '\u5206\u949F\u524D';
  }
  return '\u521A\u521A';
};

/**
 * dayOfYear：当前日期天数
 * dayOfYear(new Date()); // 285
 * @param {*} str
 * @returns
 */
export const dayOfYear = (date) => {
  Math.floor((date - new Date(date.getFullYear(), 0, 0)) / 1000 / 60 / 60 / 24);
};

/**
 * Get Time From Date：返回当前24小时制时间的字符串
 * getColonTimeFromDate(new Date());
 * "08:38:00"
 * @param {*} date
 * @returns
 */
export const getColonTimeFromDate = (date) => date.toTimeString().slice(0, 8);

/**
 * Get Days Between Dates：返回日期间隔的天数
 * getDaysDiffBetweenDates(new Date('2019-01-01'), new Date('2019-10-14')); // 286
 * @param {*} dateInitial
 * @param {*} dateFinal
 * @returns
 */
export const getDaysDiffBetweenDates = (dateInitial, dateFinal) => {
  (dateFinal - dateInitial) / (1000 * 3600 * 24);
};

/**
 * isAfterDate：检查是否在某日期后
 * isAfterDate(new Date(2010, 10, 21), new Date(2010, 10, 20)); // true
 * @param {*} dateA
 * @param {*} dateB
 * @returns
 */
export const isAfterDate = (dateA, dateB) => dateA > dateB;

/**
 * isBeforeDate：检查是否在某日期前
 * isBeforeDate(new Date(2010, 10, 20), new Date(2010, 10, 21)); // true
 * @param {*} dateA
 * @param {*} dateB
 * @returns
 */
export const isBeforeDate = (dateA, dateB) => dateA < dateB;

/**
 * tomorrow：获取明天的字符串格式时间
 * tomorrow(); // 2019-10-15 (如果明天是2019-10-15)
 * @returns
 */
export const tomorrow = () => {
  let t = new Date();
  t.setDate(t.getDate() + 1);
  return t.toISOString().split('T')[0];
};

/**
 * 时间回调
 * @param {*} callback
 * @param {*} time
 */
export const nTime = (callback, time = 5000) => {
  setTimeout(() => {
    callback =
      callback ||
      function () {
        return true;
      };
  }, time);
};

/**
 * 今天是周几
 * @returns
 */
export const getWeek = () => {
  return '日一二三四五六'.charAt(new Date().getDay());
};

/**
 * 当前时间语义化
 * @returns
 */
export const getDateState = () => {
  var now = new Date();
  var hour = now.getHours();
  if (hour > 5 && hour < 9) {
    return '早上';
  } else if (hour < 12) {
    return '上午';
  } else if (hour < 14) {
    return '中午';
  } else if (hour < 17) {
    return '下午';
  } else if (hour < 19) {
    return '傍晚';
  } else if (hour < 22) {
    return '晚上';
  } else {
    return '深夜';
  }
};
