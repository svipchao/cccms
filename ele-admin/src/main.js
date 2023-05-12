import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import router from "./router";
import permission from "./utils/directive/permission.js";
import { createPersistedState } from "pinia-plugin-persistedstate";
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

app.use(router);
app.mount("#app");
