import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import router from "./router";
import Antd from "ant-design-vue";
import { createPersistedState } from "pinia-plugin-persistedstate";
import "ant-design-vue/dist/reset.css";
import "@/assets/remixicon/remixicon.css";
import "@/assets/app.less";

const app = createApp(App);

const pinia = createPinia();
pinia.use(
  createPersistedState({
    key: (id) => `cccms_${id}`,
    storage: localStorage,
  })
);
app.use(pinia);
app.use(Antd);
app.use(router);

app.mount("#app");
