import { timestamp } from '../time';

function addWaterMarker(parentNode, options) {
  if (options.text.indexOf('./') > -1) {
    options.imageSrc = options.text;
    options.text = '';
  } else {
    options.text = options.text;
    options.imageSrc = '';
  }
  // 水印文字，父元素，字体，文字颜色
  new WaterMark(parentNode, options);
}
const waterMarker = {
  mounted: function (el, binding) {
    addWaterMarker(el, binding.value);
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
  this._fillContent();
  this._bindEvent(node);
}
WaterMark.prototype = {
  /* 初始化 */
  _init: function (node, options) {
    this.options = {
      node: node, // 添加水印的节点
      text: options.text ? options.text : '', // 水印文字内容
      textTime: options.textTime ? options.textTime : true, // 水印文字是否添加时间戳
      timestamp: options.timestamp ? options.timestamp : 'yyyy-MM-dd HH:mm:ss', // 水印文字时间戳格式
      opacity: options.opacity ? options.opacity : 0.2, // 水印透明度
      xSpace: options.xSpace ? options.xSpace : 40, // 横向间隔
      ySpace: options.ySpace ? options.ySpace : 20, // 纵向间隔
      markWidth: options.markWidth ? options.markWidth : 0, // 水印宽度
      markHeight: options.markHeight ? options.markHeight : 0, // 水印高度
      angle: options.angle ? options.angle : 10, // 倾斜角度
      fontSize: options.fontSize ? options.fontSize : 16, // 字体大小
      color: options.color ? options.color : '#000', // 字体颜色
      fontFamily: options.fontFamily ? options.fontFamily : '微软雅黑', // 字体
      imageSrc: options.imageSrc ? options.imageSrc : '', // 图片地址
    };
    node.style.overflow = 'hidden';
  },
  /* 填充水印 */
  _fillContent: function () {
    this.markContainer = document.createElement('div');
    this.markContainer.style.opacity = this.options.opacity;
    this.markContainer.className = 'water-box';
    this.markContainer.style.position = 'absolute';
    this.markContainer.style.width = '100%';
    this.markContainer.style.height = '100%';
    this.markContainer.style.zIndex = '99999';
    this.markContainer.style.pointerEvents = 'none';
    let imageSrc = '';
    if (this.options.imageSrc) {
      imageSrc = this.options.imageSrc;
      this.markContainer.style.backgroundSize = `${
        this.options.markWidth || 100
      }px ${this.options.markHeight || 50}px`;
    } else {
      // 定义
      let textTime = '';
      if (this.options.text.length > 0 && this.options.textTime) {
        textTime = timestamp(
          Date.parse(new Date()) / 1000,
          this.options.timestamp || 'yyyy-MM-dd HH:mm:ss'
        );
      }
      let tempElement = document.createElement('span');
      // 设置你需要测量的字体样式
      tempElement.style.font = `100 ${this.options.fontSize}px ${this.options.fontFamily}`;
      // 使用一个字符来测量字体宽度
      tempElement.innerText = this.options.text;
      // 将临时元素添加到文档中
      document.body.appendChild(tempElement);
      // 获取临时元素的宽度
      let fontWidth1 = tempElement.offsetWidth;
      // 获取临时元素的宽度
      let fontHeight1 = tempElement.offsetHeight;
      // 使用一个字符来测量字体宽度
      tempElement.innerText = textTime;
      // 将临时元素添加到文档中
      document.body.appendChild(tempElement);
      // 获取临时元素的宽高
      let fontWidth2 = tempElement.offsetWidth;
      let fontHeight2 = tempElement.offsetHeight;
      // 将临时元素从文档中移除
      document.body.removeChild(tempElement);
      let strLength = Math.max(this.options.text.length, textTime.length);
      let fontWidth = Math.max(fontWidth1, fontWidth2);
      let fontHeight = Math.max(fontHeight1, fontHeight2);

      let canvas = document.createElement('canvas');
      canvas.width = this.options.markWidth || fontWidth + this.options.xSpace;
      canvas.height =
        this.options.markHeight ||
        fontHeight + (strLength * fontHeight) / 9 + this.options.ySpace;
      if (textTime != '') canvas.height += fontHeight;
      let watermarker = canvas.getContext('2d');
      // 保存当前的转换矩阵
      watermarker.save();
      // 将画布的位置平移到水印图像的位置
      watermarker.translate(canvas.width / 2, canvas.height / 2);
      // 以水印图像的中心点为原点进行旋转
      // 计算旋转角度和缩放比例
      watermarker.rotate((-this.options.angle * Math.PI) / 180);
      // 描边
      // watermarker.strokeRect(0, 0, canvas.width, canvas.height);
      watermarker.fillStyle = this.options.color;
      watermarker.font = `100 ${this.options.fontSize}px ${this.options.fontFamily}`;
      if (textTime != '') {
        watermarker.fillText(
          this.options.text,
          -(fontWidth / 2),
          -fontHeight / 4
        );
        watermarker.fillText(
          textTime,
          -(fontWidth / 2),
          -(fontHeight / 4) + 20
        );
      } else {
        watermarker.fillText(
          this.options.text,
          -(fontWidth / 2),
          fontHeight / 4
        );
      }
      // 恢复之前的转换矩阵
      watermarker.restore();
      imageSrc = canvas.toDataURL('image/png');
    }
    this.markContainer.style.backgroundImage = `url('${imageSrc}')`;
    this.options.node.appendChild(this.markContainer);
  },
  /* 事件监听 */
  _bindEvent: function (node) {
    var that = this;
    // 在 onMounted 里边创建一个 MutationObserver 来进行监控
    // 一旦某个东西有变化就会运行这个回调函数
    var elementTimer;
    let ob = new MutationObserver((records) => {
      if (elementTimer) clearTimeout(elementTimer);
      if (['water-box'].indexOf(records[0]?.removedNodes[0]?.className) > -1) {
        elementTimer = setTimeout(() => {
          // 并把变化记录下来传递给我们
          that.refresh();
        }, 0);
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
      this.markContainer.remove();
    }
    this._init(this.options.node, this.options);
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
