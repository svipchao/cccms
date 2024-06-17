// 根据窗口宽度返回不同值
export const useWindowWidthSize = (
  width = 0,
  yesValue = 'vertical',
  noValue = 'horizontal'
) => {
  return window.innerWidth < width ? yesValue : noValue;
};
