import { createApp } from 'vue';
import App from './App.vue';
import store from './stores';
import router from './router';
import ArcoVue from '@arco-design/web-vue';
import '@arco-design/web-vue/dist/arco.less';
import 'remixicon/fonts/remixicon.css';
import '@/assets/app.less';
import 'nprogress/nprogress.css';

const app = createApp(App);

app.use(store);
app.use(router);
app.use(ArcoVue);
app.mount('#app');