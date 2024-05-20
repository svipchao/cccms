import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import ArcoVue from '@arco-design/web-vue';
import { createPersistedState } from 'pinia-plugin-persistedstate';
import '@arco-design/web-vue/dist/arco.less';
import 'remixicon/fonts/remixicon.css';
import '@/assets/app.less';

const app = createApp(App);

const pinia = createPinia();
pinia.use(
  createPersistedState({
    key: (id) => `cccms_${id}`,
    storage: localStorage,
  })
);
app.use(pinia);

app.use(ArcoVue);
app.use(router);
app.mount('#app');

// import { createApp } from 'vue';
// import { createPinia } from 'pinia';
// import App from './App.vue';
// import router from './router';
// import ArcoVue from '@arco-design/web-vue';
// import permission from './utils/directive/permission.js';
// import waterMarker from './utils/directive/waterMarker.js';
// import focus from './utils/directive/focus.js';
// import print from 'vue3-print-nb';
// import { createPersistedState } from 'pinia-plugin-persistedstate';
// import '@arco-design/web-vue/dist/arco.less';
// import 'remixicon/fonts/remixicon.css';
// import '@/assets/app.less';

// const app = createApp(App);
// app.directive('permission', permission);
// app.directive('waterMarker', waterMarker);
// app.directive('focus', focus);
// app.directive('print', print);

// const pinia = createPinia();
// pinia.use(
//   createPersistedState({
//     key: (id) => `cccms_${id}`,
//     storage: localStorage,
//   })
// );
// app.use(pinia);

// app.use(ArcoVue);
// app.use(router);
// app.mount('#app');
