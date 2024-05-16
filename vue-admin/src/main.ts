// https://unocss.dev/ 原子 css 库
import '@unocss/reset/tailwind-compat.css'; // unocss reset
import 'virtual:uno.css';
import 'virtual:unocss-devtools';

// 你自定义的 css
import './styles/main.css';
import '@arco-design/web-vue/dist/arco.css';

import ArcoVue from '@arco-design/web-vue';
import App from './App.vue';

const app = createApp(App);
app.use(ArcoVue);
app.mount('#app');
