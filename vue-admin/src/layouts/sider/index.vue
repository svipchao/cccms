<template>
  <div>
    <div class="arco-layout-sider-system">
      <a-select
        placeholder="选择应用..."
        :model-value="menuStore.getCurrentAppId"
        @change="changeAppFun"
      >
        <a-option v-for="app in menuStore.menus" :key="app.id" :value="app.id">
          {{ app.name }}
        </a-option>
      </a-select>
      <a-divider />
    </div>
    <div class="arco-layout-sider-menu">
      <a-menu
        mode="vertical"
        :level-indent="10"
        :accordion="true"
        @menu-item-click="menuStore.setSetSelectedKeys"
        @sub-menu-click="menuStore.setOpenKeys"
        :default-open-keys="[menuStore.getOpenKeys]"
        :default-selected-keys="[menuStore.getSelectedKeys]"
      >
        <sider-menu
          :menus="menuStore.getCurrentMenu"
          v-if="menuStore.getCurrentMenu !== undefined"
        />
        <a-empty v-else />
      </a-menu>
    </div>
    <div class="arco-layout-sider-copyright">
      <span>&copy;{{ new Date().getFullYear() }}&nbsp;</span>
      <a :href="config.copyrightUrl" target="_blank">{{ config.copyrightTitle }}</a>
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import SiderMenu from "./sider-menu.vue";
import config from "@/config";
import { useUser } from "@/store/user.js";
import { useMenu } from "@/store/menu.js";

const userStore = useUser();
const menuStore = useMenu();

const userInfoModal = ref(userStore.role_id === 0);

// 切换应用
const changeAppFun = (value) => {
  menuStore.setCurrentApp(value);
};
</script>
