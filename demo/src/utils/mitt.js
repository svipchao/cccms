import mitt from 'mitt';

/** 全局公共事件需要在此处添加类型 */

export const emitter = mitt();

// import { emitter } from "@/utils/mitt.js";

// 发布
// emitter.emit("onReloadTaskData", data);

// 接收
// emitter.on('onReloadTaskData', (data) => {
//   console.log(data);
// });

// 取消订阅
// onBeforeUnmount(() => {
//   // 解绑`onReloadTaskData`公共事件，防止多次触发
//   emitter.off("onReloadTaskData");
// });
