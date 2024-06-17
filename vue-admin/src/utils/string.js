/**
 * byteSize：返回字符串的字节长度
 * byteSize('😀'); // 4
 * byteSize('Hello World'); // 11
 * @param {*} fn
 * @param  {...any} args
 * @returns
 */
export const byteSize = (str) => new Blob([str]).size;

/**
 * capitalize：首字母大写
 * capitalize('fooBar'); // 'FooBar'
 * capitalize('fooBar', true); // 'Foobar'
 * @param {*} param0
 * @returns
 */
export const capitalize = ([first, ...rest]) => first.toUpperCase() + rest.join("");

/**
 * capitalizeEveryWord：每个单词首字母大写
 * capitalizeEveryWord('hello world!'); // 'Hello World!'
 * @param {*} str
 * @returns
 */
export const capitalizeEveryWord = (str) => str.replace(/\b[a-z]/g, (char) => char.toUpperCase());

/**
 * decapitalize：首字母小写
 * decapitalize('FooBar'); // 'fooBar'
 * decapitalize('FooBar'); // 'fooBar'
 * @param {*} param0
 * @returns
 */
export const decapitalize = ([first, ...rest]) => first.toLowerCase() + rest.join("");

/**
 * luhnCheck：银行卡号码校验（luhn算法）
 * luhnCheck('4485275742308327'); // true
 * luhnCheck(6011329933655299); // false
 * luhnCheck(123456789); // false
 * @param {*} num
 * @returns
 */
export const luhnCheck = (num) => {
  let arr = (num + "")
    .split("")
    .reverse()
    .map((x) => parseInt(x));
  let lastDigit = arr.splice(0, 1)[0];
  let sum = arr.reduce((acc, val, i) => (i % 2 !== 0 ? acc + val : acc + ((val * 2) % 9) || 9), 0);
  sum += lastDigit;
  return sum % 10 === 0;
};

/**
 * splitLines：将多行字符串拆分为行数组。
 * splitLines('This\nis a\nmultiline\nstring.\n');
 * ['This', 'is a', 'multiline', 'string.' , '']
 * @param {*} str
 * @returns
 */
export const splitLines = (str) => str.split(/\r?\n/);

/**
 * stripHTMLTags：删除字符串中的HTMl标签
 * stripHTMLTags('<p><em>lorem</em> <strong>ipsum</strong></p>');
 * 'lorem ipsum'
 * @param {*} str
 * @returns
 */
export const stripHTMLTags = (str) => str.replace(/<[^>]*>/g, "");

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
 * 字符串转换
 * @param {*} str
 * @returns
 */
export const praseStrEmpty = (str) => {
  if (!str || str == "undefined" || str == "null") {
    return "";
  }
  return str;
};

/**
 * 字符串提取数字
 * @param {*} str
 * @returns
 */
export const extractInt = (str) => {
  return str.replace(/[^0-9]/gi, "");
};

/**
 * 截取字符串并加身略号
 * @param {*} str
 * @param {*} length
 * @returns
 */
export function subText(str, length) {
  if (str.length === 0) {
    return "";
  }
  if (str.length > length) {
    return str.substr(0, length) + "...";
  } else {
    return str;
  }
}
