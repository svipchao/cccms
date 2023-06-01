import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import Antd from "ant-design-vue";
import { createPersistedState } from "pinia-plugin-persistedstate";
import "ant-design-vue/dist/reset.css";
import "@/assets/vars.less";
// import "@/assets/app.less";
import "@/assets/remixicon/remixicon.css";

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

app.mount("#app");
