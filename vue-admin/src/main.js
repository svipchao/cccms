import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import router from "./router";
import ArcoVue from "@arco-design/web-vue";
import piniaPluginPersistedstate from "pinia-plugin-persistedstate";
import permission from "./utils/directive/permission.js";
import "@/assets/fonts/iconfont.css";
import "@arco-design/web-vue/dist/arco.less";
import "@/assets/less/app.less";

const app = createApp(App);
app.directive("permission", permission);

const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);
app.use(pinia);

app.use(ArcoVue);
app.use(router);
app.mount("#app");
