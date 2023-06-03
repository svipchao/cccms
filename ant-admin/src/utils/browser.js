/**
 * bottomVisible：检查页面底部是否可见
 * bottomVisible(); // true
 * @returns
 */
export const bottomVisible = () => {
  document.documentElement.clientHeight + window.scrollY >=
    (document.documentElement.scrollHeight || document.documentElement.clientHeight);
};

/**
 * currentURL：返回当前链接url
 * currentURL(); // 'https://www.cccms.cc'
 * @returns
 */
export const currentURL = () => window.location.href;

/**
 * distance：返回两点间的距离
 * distance(1, 1, 2, 3); // 2.23606797749979
 * @param {*} x0
 * @param {*} y0
 * @param {*} x1
 * @param {*} y1
 * @returns
 */
export const distance = (x0, y0, x1, y1) => Math.hypot(x1 - x0, y1 - y0);

/**
 * elementContains：检查是否包含子元素
 * elementContains(document.querySelector('head'), document.querySelector('title')); // true
 * elementContains(document.querySelector('body'), document.querySelector('body')); // false
 * @param {*} parent
 * @param {*} child
 * @returns
 */
export const elementContains = (parent, child) => parent !== child && parent.contains(child);

/**
 * getStyle：返回指定元素的生效样式
 * getStyle(document.querySelector('p'), 'font-size'); // '16px'
 * @param {*} el
 * @param {*} ruleName
 * @returns
 */
export const getStyle = (el, ruleName) => getComputedStyle(el)[ruleName];

/**
 * getType：返回值或变量的类型名
 * getType(new Set([1, 2, 3])); // 'set'
 * getType([1, 2, 3]); // 'array'
 * @param {*} v
 * @returns
 */
export const getType = (v) => {
  v === undefined ? "undefined" : v === null ? "null" : v.constructor.name.toLowerCase();
};

/**
 * hasClass：校验指定元素的类名
 * hasClass(document.querySelector('p.special'), 'special'); // true
 * @param {*} el
 * @param {*} className
 * @returns
 */
export const hasClass = (el, className) => el.classList.contains(className);

/**
 * hide：隐藏所有的指定标签
 * hide(document.querySelectorAll('img')); // 隐藏所有<img>标签
 * @param  {...any} el
 * @returns
 */
export const hide = (...el) => [...el].forEach((e) => (e.style.display = "none"));

/**
 * httpsRedirect：HTTP 跳转 HTTPS
 * httpsRedirect();
 * 若在`http://www.baidu.com`, 则跳转到`https://www.baidu.com`
 */
export const httpsRedirect = () => {
  if (location.protocol !== "https:") location.replace("https://" + location.href.split("//")[1]);
};

/**
 * insertAfter：在指定元素之后插入新元素
 * <div id="myId">...</div> <p>after</p>
 * insertAfter(document.getElementById('myId'), '<p>after</p>');
 * @param {*} el
 * @param {*} htmlString
 * @returns
 */
export const insertAfter = (el, htmlString) => el.insertAdjacentHTML("afterend", htmlString);

/**
 * insertBefore：在指定元素之前插入新元素
 * insertBefore(document.getElementById('myId'), '<p>before</p>');
 * <p>before</p> <div id="myId">...</div>
 * @param {*} el
 * @param {*} htmlString
 * @returns
 */
export const insertBefore = (el, htmlString) => el.insertAdjacentHTML("beforebegin", htmlString);

/**
 * isBrowser：检查是否为浏览器环境
 * isBrowser(); // true (browser)
 * isBrowser(); // false (Node)
 * @returns
 */
export const isBrowser = () => ![typeof window, typeof document].includes("undefined");

/**
 * isBrowserTab：检查当前标签页是否活动
 * isBrowserTabFocused(); // true
 * @returns
 */
export const isBrowserTabFocused = () => !document.hidden;

/**
 * nodeListToArray：转换nodeList为数组
 * nodeListToArray(document.childNodes);
 * [ <!DOCTYPE html>, html ]
 * @param {*} nodeList
 * @returns
 */
export const nodeListToArray = (nodeList) => [...nodeList];

/**
 * scrollToTop：平滑滚动至顶部
 * scrollToTop();
 */
export const scrollToTop = () => {
  const c = document.documentElement.scrollTop || document.body.scrollTop;
  if (c > 0) {
    window.requestAnimationFrame(scrollToTop);
    window.scrollTo(0, c - c / 8);
  }
};

/**
 * smoothScroll：滚动到指定元素区域
 * smoothScroll('#fooBar');
 * smoothScroll('.fooBar');
 * @param {*} element
 * @returns
 */
export const smoothScroll = (element) => {
  document.querySelector(element).scrollIntoView({
    behavior: "smooth",
  });
};

/**
 * detectDeviceType：检测移动/PC设备
 * @returns
 */
export const detectDeviceType = () => {
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/g.test(navigator.userAgent)
    ? "Mobile"
    : "Desktop";
};

/**
 * getScrollPosition：返回当前的滚动位置
 * getScrollPosition(); // {x: 0, y: 200}
 * @param {*} el
 * @returns
 */
export const getScrollPosition = (el = window) => ({
  x: el.pageXOffset !== undefined ? el.pageXOffset : el.scrollLeft,
  y: el.pageYOffset !== undefined ? el.pageYOffset : el.scrollTop,
});
