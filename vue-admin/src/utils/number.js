/**
 * randomIntegerInRange：生成指定范围的随机整数
 * randomIntegerInRange(0, 5); // 3
 * @param {*} m
 * @param {*} n
 * @returns
 */
export const randomIntegerInRange = (m, n) => Math.floor(Math.random() * (m - n) + n);

/**
 * randomNumberInRange：生成指定范围的随机小数
 * randomNumberInRange(2, 10); // 6.0211363285087005
 * @param {*} min
 * @param {*} max
 * @returns
 */
export const randomNumberInRange = (min, max) => Math.random() * (max - min) + min;

/**
 * round：四舍五入到指定位数
 * round(1.005, 2); // 1.01
 * @param {*} n
 * @param {*} decimals
 * @returns
 */
export const round = (n, decimals = 0) => Number(`${Math.round(`${n}e${decimals}`)}e-${decimals}`);

/**
 * sum：计算数组或多个数字的总和
 * sum(1, 2, 3, 4); // 10
 * sum(...[1, 2, 3, 4]); // 10
 * @param  {...any} arr
 * @returns
 */
export const sum = (...arr) => [...arr].reduce((acc, val) => acc + val, 0);

/**
 * toCurrency：简单的货币单位转换
 * toCurrency(123456.789, 'EUR'); // €123,456.79
 * toCurrency(123456.789, 'USD', 'en-us'); // $123,456.79
 * toCurrency(123456.789, 'USD', 'fa'); // ۱۲۳٬۴۵۶٫۷۹
 * toCurrency(322342436423.2435, 'JPY'); // ¥322,342,436,423
 * @param {*} n
 * @param {*} curr
 * @param {*} LanguageFormat
 * @returns
 */
export const toCurrency = (n, curr, LanguageFormat = undefined) => {
  Intl.NumberFormat(LanguageFormat, { style: "currency", currency: curr }).format(n);
};

/**
 * 数字千分位
 * @param num 数字
 */
export const formatNumber = (num) => {
  return String(num != null ? num : "").replace(/(\d{1,3})(?=(\d{3})+(?:$|\.))/g, "$1,");
};

/**
 * 数字超过规定大小加上加号“+”，如数字超过99显示99+
 * @param { number } val 输入的数字
 * @param { number } maxNum 数字规定界限
 */
export const outOfNum = (val, maxNum) => {
  val = val ? val - 0 : 0;
  if (val > maxNum) {
    return `${maxNum}+`;
  } else {
    return val;
  }
};
