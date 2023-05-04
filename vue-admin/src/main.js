import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import router from "./router";
import ArcoVue from "@arco-design/web-vue";
import permission from "./utils/directive/permission.js";
import { createPersistedState } from "pinia-plugin-persistedstate";
import "@arco-design/web-vue/dist/arco.less";
import "@/assets/remixicon/remixicon.css";
import "@/assets/app.less";

const app = createApp(App);
app.directive("permission", permission);

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
app.mount("#app");
