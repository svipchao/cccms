/**
 * attempt：捕获函数运行异常
 * var elements = attempt(function(selector) {
 *   return document.querySelectorAll(selector);
 * }, '>_>');
 * if (elements instanceof Error) elements = []; // elements = []
 * @param {*} fn
 * @param  {...any} args
 * @returns
 */
export const attempt = (fn, ...args) => {
  try {
    return fn(...args);
  } catch (e) {
    return e instanceof Error ? e : new Error(e);
  }
};

/**
 * defer：推迟执行
 * defer(console.log, 'a'), console.log('b');
 * logs 'b' then 'a'
 * @param {*} fn
 * @param  {...any} args
 * @returns
 */
export const defer = (fn, ...args) => setTimeout(fn, 1, ...args);

/**
 * runPromisesInSeries：运行多个Promises
 * const runPromisesInSeries = ps => ps.reduce((p, next) => p.then(next), Promise.resolve());
 * const delay = d => new Promise(r => setTimeout(r, d));
 *
 * runPromisesInSeries([() => delay(1000), () => delay(2000)]);
 * //依次执行每个Promises ，总共需要3秒钟才能完成
 */

/**
 * timeTaken：计算函数执行时间
 * @param {*} callback
 * @returns
 */
export const timeTaken = (callback) => {
  console.time('timeTaken');
  const r = callback();
  console.timeEnd('timeTaken');
  return r;
};

/**
 * once：只调用一次的函数
 * once(()=>{ console.log('只调用一次的函数') })
 * @param {*} fn
 * @returns
 */
export const once = (fn) => {
  let called = false;
  return function () {
    if (!called) {
      called = true;
      fn.apply(this, arguments);
    }
  };
};

/**
 * flattenObject：以键的路径扁平化对象
 * flattenObject({ a: { b: { c: 1 } }, d: 1 });
 * { 'a.b.c': 1, d: 1 }
 * @param {*} obj
 * @param {*} prefix
 * @returns
 */
export const flattenObject = (obj, prefix = '') => {
  Object.keys(obj).reduce((acc, k) => {
    const pre = prefix.length ? prefix + '.' : '';
    if (typeof obj[k] === 'object')
      Object.assign(acc, flattenObject(obj[k], pre + k));
    else acc[pre + k] = obj[k];
    return acc;
  }, {});
};

/**
 * unflattenObject：以键的路径展开对象
 * unflattenObject({ 'a.b.c': 1, d: 1 });
 * { a: { b: { c: 1 } }, d: 1 }
 * @param {*} obj
 * @returns
 */
export const unflattenObject = (obj) => {
  Object.keys(obj).reduce((acc, k) => {
    if (k.indexOf('.') !== -1) {
      const keys = k.split('.');
      Object.assign(
        acc,
        JSON.parse(
          '{' +
            keys
              .map((v, i) => (i !== keys.length - 1 ? `"${v}":{` : `"${v}":`))
              .join('') +
            obj[k] +
            '}'.repeat(keys.length)
        )
      );
    } else acc[k] = obj[k];
    return acc;
  }, {});
};

/**
 * forOwn：迭代属性并执行回调
 * forOwn({ foo: 'bar', a: 1 }, v => console.log(v));
 * 'bar', 1
 * @param {*} obj
 * @param {*} fn
 * @returns
 */
export const forOwn = (obj, fn) =>
  Object.keys(obj).forEach((key) => fn(obj[key], key, obj));

/**
 * is：检查值是否为特定类型。
 * is(Array, [1]); // true
 * is(ArrayBuffer, new ArrayBuffer()); // true
 * is(Map, new Map()); // true
 * is(RegExp, /./g); // true
 * is(Set, new Set()); // true
 * is(WeakMap, new WeakMap()); // true
 * is(WeakSet, new WeakSet()); // true
 * is(String, ''); // true
 * is(String, new String('')); // true
 * is(Number, 1); // true
 * is(Number, new Number(1)); // true
 * is(Boolean, true); // true
 * is(Boolean, new Boolean(true)); // true
 * @param {*} type
 * @param {*} val
 * @returns
 */
export const is = (type, val) =>
  ![, null].includes(val) && val.constructor === type;

/**
 * equals：全等判断
 * equals({ a: [2, { e: 3 }], b: [4], c: 'foo' }, { a: [2, { e: 3 }], b: [4], c: 'foo' }); // true
 * @param {*} a
 * @param {*} b
 * @returns
 */
export const equals = (a, b) => {
  if (a === b) return true;
  if (a instanceof Date && b instanceof Date)
    return a.getTime() === b.getTime();
  if (!a || !b || (typeof a !== 'object' && typeof b !== 'object'))
    return a === b;
  if (a.prototype !== b.prototype) return false;
  let keys = Object.keys(a);
  if (keys.length !== Object.keys(b).length) return false;
  return keys.every((k) => equals(a[k], b[k]));
};

/**
 * size：获取不同类型变量的字节长度
 * size([1, 2, 3, 4, 5]); // 5
 * size('size'); // 4
 * size({ one: 1, two: 2, three: 3 }); // 3
 * @param {*} val
 * @returns
 */
export const size = (val) => {
  Array.isArray(val)
    ? val.length
    : val && typeof val === 'object'
    ? val.size || val.length || Object.keys(val).length
    : typeof val === 'string'
    ? new Blob([val]).size
    : 0;
};

/**
 * escapeHTML：转义HTML
 * escapeHTML('<a href="#">Me & you</a>');
 * '&lt;a href=&quot;#&quot;&gt;Me &amp; you&lt;/a&gt;'
 * @param {*} str
 * @returns
 */
export const escapeHTML = (str) => {
  str.replace(/[&<>'"]/g, (tag) => {
    ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      "'": '&#39;',
      '"': '&quot;',
    })[tag] || tag;
  });
};

/**
 * 转义字符还原成html字符
 * @param str
 * @returns {string}
 * @constructor
 */
export const restoreHtml = (str) => {
  var s = '';
  if (str.length === 0) {
    return '';
  }
  s = str.replace(/&amp;/g, '&');
  s = s.replace(/&lt;/g, '<');
  s = s.replace(/&gt;/g, '>');
  s = s.replace(/&nbsp;/g, ' ');
  s = s.replace(/&#39;/g, "'");
  s = s.replace(/&quot;/g, '"');
  return s;
};

/**
 * Random Hexadecimal Color Code：随机十六进制颜色
 * randomHexColorCode(); // "#e34155"
 * @returns
 */
export const randomHexColorCode = () => {
  let n = (Math.random() * 0xfffff * 1000000).toString(16);
  return '#' + n.slice(0, 6);
};

// 随机颜色
export const randColor = () => {
  const colors = [
    'rgb(24, 144, 255)',
    'rgb(102, 181, 255)',
    'rgb(65, 217, 199)',
    'rgb(47, 194, 91)',
    'rgb(110, 219, 143)',
    'rgb(154, 230, 92)',
    'rgb(250, 204, 20)',
    'rgb(230, 150, 92)',
    'rgb(87, 173, 113)',
    'rgb(34, 50, 115)',
    'rgb(115, 138, 230)',
    'rgb(117, 100, 204)',
    'rgb(133, 67, 224)',
    'rgb(168, 119, 237)',
    'rgb(92, 142, 230)',
    'rgb(19, 194, 194)',
    'rgb(112, 224, 224)',
    'rgb(92, 163, 230)',
    'rgb(52, 54, 199)',
    'rgb(128, 130, 255)',
    'rgb(221, 129, 230)',
    'rgb(240, 72, 100)',
    'rgb(250, 125, 146)',
    'rgb(213, 152, 217)',
  ];
  return colors[
    Math.floor(Math.random() * (0 - colors.length) + colors.length)
  ];
};

/**
 * 复制字符串到剪切板
 * @param {*} text
 * @param {*} success
 * @param {*} error
 */
export const copyText = (text, success, error) => {
  const copyDom = document.createElement('textarea');
  copyDom.style.position = 'absolute';
  copyDom.style.top = '-9999px';
  copyDom.value = text;
  document.body.appendChild(copyDom);
  return new Promise((resolve) => {
    setTimeout(() => {
      try {
        copyDom.select();
        document.execCommand('Copy');
        document.body.removeChild(copyDom);
        success && success();
        resolve(true);
      } catch (err) {
        error && error();
        resolve(false);
      }
    }, 100);
  });
};

/**
 * 点击N次执行方法
 * @param {*} callback
 * @param {*} n
 */
export const nClick = (callback, n = 2) => {
  let timer;
  if (timer) {
    clearTimeout(timer);
  }
  callback =
    callback ||
    function () {
      console.log('执行回调');
    };
  if (event.detail == n) {
    callback();
  }
};
