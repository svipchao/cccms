import { timestamp } from '../time';

function addWaterMarker(options, parentNode) {
  if (options.text.indexOf('./') > -1) {
    options.text = '';
    options.imageSrc = options.text;
  } else {
    options.text = options.text;
    options.imageSrc = '';
  }
  if (options.text.length > 0 && options.textTime) {
    options.textTime = timestamp(
      Date.parse(new Date()) / 1000,
      options.timestamp || 'yyyy-MM-dd HH:mm:ss'
    );
  }
  // 水印文字，父元素，字体，文字颜色
  new WaterMark(parentNode, options);
}

const waterMarker = {
  mounted: function (el, binding) {
    addWaterMarker(binding.value, el);
  },
};

/*
 * 水印
 * 说明: 可以添加图片水印和文字水印
 * 当水印容器大小发生变化时需要调用refresh方法重新填充水印
 */
function WaterMark(node, options) {
  if (!options) {
    options = {};
  }
  this._init(node, options);
  this._resizeSpace();
  this._fillContent();
  this._bindEvent(node);
}
WaterMark.prototype = {
  /* 初始化 */
  _init: function (node, options) {
    this.options = {
      node: node, // 添加水印的节点
      text: options.text ? options.text : '', // 水印文字内容
      textTime: options.textTime ? options.textTime : false, // 水印文字是否添加时间戳
      timestamp: options.timestamp ? options.timestamp : 'yyyy-MM-dd HH:mm:ss', // 水印文字时间戳格式
      opacity: options.opacity ? options.opacity : 0.1, // 水印透明度
      startX: options.startX ? options.startX : 0, // X轴开始位置
      startY: options.startY ? options.startY : 15, // Y轴开始位置
      xSpace: 100, // 横向间隔
      ySpace: 50, // 纵向间隔
      rows: 0, // 行数
      cols: 0, // 列数
      markWidth: options.markWidth ? options.markWidth : 200, // 水印宽度
      markHeight: options.markHeight ? options.markHeight : 50, // 水印高度
      angle: options.angle ? options.angle : 20, // 倾斜角度
      fontSize: options.fontSize ? options.fontSize : 12, // 字体大小
      color: options.color ? options.color : '#000', // 字体颜色
      fontFamily: options.fontFamily ? options.fontFamily : '微软雅黑', // 字体
      imageSrc: options.imageSrc ? options.imageSrc : '', // 图片地址
      timer: () => {}, // 监听水印变化防抖
    };
    node.style.overflow = 'hidden';
  },
  /* 自动调整每个水印间距 使其可以填充整个页面 */
  _resizeSpace: function () {
    this.pageWidth = Math.max(
      this.options.node.scrollWidth,
      this.options.node.clientWidth
    );
    this.pageHeight =
      1 +
      Math.max(this.options.node.scrollHeight, this.options.node.clientHeight);
    // 计算旋转后的元素所占宽度和高度
    // var radian = this.options.angle / 180 * Math.PI;
    // var newMarkHeight = this.options.markHeight * Math.cos(radian) + this.options.markWidth * Math.sin(radian);

    // 获取水印行数 并根据行数调整间距使水印填满屏幕
    this.options.rows = Math.ceil(
      (this.pageHeight - this.options.startY) /
        (this.options.markHeight + this.options.ySpace)
    );
    this.options.ySpace = Math.floor(
      (this.pageHeight - this.options.startY) / this.options.rows -
        this.options.markHeight
    );
    // 获取水印列数 并根据列数调整间距使水印填满屏幕
    this.options.cols =
      1 +
      Math.ceil(
        (this.pageWidth - this.options.startX) /
          (this.options.markWidth + this.options.xSpace)
      );
    this.options.xSpace = Math.floor(
      (this.pageWidth - this.options.startX) / this.options.cols -
        this.options.markWidth
    );
  },
  /* 填充水印 */
  _fillContent: function () {
    var domTemp = document.createDocumentFragment();
    for (var i = 0; i < this.options.rows; i++) {
      var posY =
        i * (this.options.markHeight + this.options.ySpace) +
        this.options.startY;
      for (var j = 0; j < this.options.cols; j++) {
        var posX =
          j * (this.options.markWidth + this.options.xSpace) +
          this.options.startX;
        domTemp.appendChild(this._createWaterMark(posX, posY));
      }
    }
    this.markContainer = document.createElement('div');
    this.markContainer.className = 'water-mark-container';
    this.markContainer.appendChild(domTemp);
    this.markContainer.style.position = 'absolute';
    this.markContainer.style.width = '100%';
    this.markContainer.style.height = '100%';
    this.markContainer.style.zIndex = '99999';
    this.markContainer.style.pointerEvents = 'none';
    this.options.node.appendChild(this.markContainer);
  },
  /* 构造每个水印节点 */
  _createWaterMark: function (x, y) {
    var markDiv = document.createElement('div');
    markDiv.className = 'water-mark-item';
    if (this.options.imageSrc) {
      markDiv.innerHTML =
        '<div>' +
        this.options.text +
        "</div><img src='" +
        this.options.imageSrc +
        "'/>";
    } else {
      let text = this.options.text;
      if (this.options.textTime) {
        text = text + '\n' + this.options.textTime;
      }
      markDiv.appendChild(document.createTextNode(text));
    }
    //设置水印div倾斜显示
    markDiv.style.webkitTransform = 'rotate(-' + this.options.angle + 'deg)';
    markDiv.style.MozTransform = 'rotate(-' + this.options.angle + 'deg)';
    markDiv.style.msTransform = 'rotate(-' + this.options.angle + 'deg)';
    markDiv.style.OTransform = 'rotate(-' + this.options.angle + 'deg)';
    markDiv.style.transform = 'rotate(-' + this.options.angle + 'deg)';
    markDiv.style.position = 'absolute';
    markDiv.style.whiteSpace = 'pre-line';
    markDiv.style.left = x + 'px';
    markDiv.style.top = y + 'px';
    markDiv.style.overflow = 'hidden';
    markDiv.style.zIndex = '99999';
    markDiv.style.width = this.options.markWidth + 'px';
    markDiv.style.height = this.options.markHeight + 'px';
    markDiv.style.display = 'block';
    markDiv.style.pointerEvents = 'none';
    markDiv.style.opacity = this.options.opacity;
    markDiv.style.textAlign = 'center';
    markDiv.style.fontFamily = this.options.fontFamily;
    markDiv.style.fontSize = this.options.fontSize;
    markDiv.style.color = this.options.color;
    return markDiv;
  },
  /* 事件监听 */
  _bindEvent: function (node) {
    var that = this;
    // 监听浏览器大小变化事件 动态调整水印
    window.onresize = function () {
      that.refresh();
    };
    // 在 onMounted 里边创建一个 MutationObserver 来进行监控
    // 一旦某个东西有变化就会运行这个回调函数
    var timer;
    let ob = new MutationObserver((records) => {
      if (timer) clearTimeout(timer);
      if (
        ['water-mark-container', 'water-mark-item'].indexOf(
          records[0]?.removedNodes[0]?.className
        ) > -1
      ) {
        timer = setTimeout(() => {
          // 并把变化记录下来传递给我们
          that.refresh();
        }, 100);
      }
    });
    // 创建好监听器之后，告诉监听器需要监听的元素
    ob.observe(node, {
      // 监听的时候需要加一些配置
      childList: true, // 元素内容有没有发生变化
      attributes: true, // 元素本身的属性有没有发生变化
      subtree: true, // 告诉它监控的是整个子树，就是包含整个子元素
    });
  },
  /* 刷新水印 水印容器大小发生变化是调用 */
  refresh: function () {
    if (this.markContainer) {
      this.markContainer.innerHTML = '';
    }
    this._init(this.options.node, this.options);
    this._resizeSpace();
    this._fillContent();
  },
  /* 显示水印图层 */
  show: function () {
    this.markContainer.style.display = 'block';
  },
  /* 隐藏水印图层 */
  hide: function () {
    this.markContainer.style.display = 'none';
  },
};
// 水印
export default waterMarker;
