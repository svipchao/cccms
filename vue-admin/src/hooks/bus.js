import { ref } from 'vue';

class Bus {
  constructor() {
    // 收集订阅信息,调度中心
    (this.eventList = {}), // 事件列表，这项是必须的
      // 下面的都是自定义值
      (this.msg = ref('这是一条总线的信息'));
  }

  // 订阅
  $on(name, fn) {
    this.eventList[name] = this.eventList[name] || [];
    this.eventList[name].push(fn);
  }

  // 发布
  $emit(name, data) {
    if (this.eventList[name]) {
      this.eventList[name].forEach((fn) => {
        fn(data);
      });
    }
  }

  // 取消订阅
  $off(name) {
    if (this.eventList[name]) {
      delete this.eventList[name];
    }
  }
}

export default new Bus();

// 发布
// Bus.$emit('onReloadTaskData', data);

// 接收
// Bus.$on('onReloadTaskData', (data) => {
//   console.log(data);
// });

// 取消订阅
// Bus.$off('onReloadTaskData');

// vue3不建议使用bus 可以尝试使用pinia状态库替代
