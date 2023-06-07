<template>
  <div class="ant-layout-sider-system">
    <a-space-compact direction="vertical" block>
      <a-row style="flex-flow: row nowrap">
        <Userinfo />
      </a-row>
      <a-row style="align-items: center">
        <a-col flex="auto" style="text-align: center">
          <a-tooltip content="切换系统应用" position="tl">
            <a-button type="text" @click="showApps">
              <template #icon>
                <i
                  :class="currentApp?.icon || 'ri-apps-line'"
                  :style="{
                    color: 'rgb(var(--primary-6))',
                  }"
                  v-if="menuStore.showApps"
                ></i>
                <i :class="currentApp?.icon || 'ri-apps-line'" v-else></i>
              </template>
            </a-button>
          </a-tooltip>
        </a-col>
        <a-col flex="10px">
          <a-divider type="vertical" margin="4.5px" />
        </a-col>
        <a-col flex="auto" style="text-align: center">
          <a-tooltip
            :content="themeStore.getTheme ? '切换亮色模式' : '切换暗黑模式'"
            position="tl"
          >
            <a-button type="text" @click="themeStore.switchTheme()">
              <template #icon>
                <i
                  class="ri-moon-fill"
                  style="color: #fff"
                  v-if="themeStore.getTheme"
                ></i>
                <i class="ri-sun-fill" style="color: #000" v-else></i>
              </template>
            </a-button>
          </a-tooltip>
        </a-col>
        <a-col flex="10px">
          <a-divider type="vertical" margin="4.5px" />
        </a-col>
        <a-col flex="auto" style="text-align: center">
          <a-tooltip content="清楚缓存" position="tl">
            <a-button type="text" @click="userStore.setAccessToken()">
              <template #icon>
                <i class="ri-refresh-line"></i>
              </template>
            </a-button>
          </a-tooltip>
        </a-col>
        <a-col flex="10px">
          <a-divider type="vertical" margin="4.5px" />
        </a-col>
        <a-col flex="auto" style="text-align: center">
          <a-tooltip content="注销登录" position="tl">
            <a-button type="text" @click="userStore.logout()">
              <template #icon>
                <i class="ri-logout-box-line"></i>
              </template>
            </a-button>
          </a-tooltip>
        </a-col>
      </a-row>
    </a-space-compact>
    <a-divider />
  </div>
  <div class="arco-layout-sider-menu">
    <a-menu
      mode="vertical"
      :level-indent="10"
      accordion
      auto-open-selected
      :selected-keys="[menuStore.getSelectedKeys]"
      @menu-item-click="tabsStore.switchTab"
    >
      <sider-menu
        :menus="menuStore.currentMenus"
        v-if="menuStore.currentMenus !== undefined"
      />
      <a-empty v-else />
    </a-menu>
  </div>
  <div class="footer-copyright">
    <span>&copy;{{ new Date().getFullYear() }}&nbsp;</span>
    <a :href="config.copyrightUrl" target="_blank">
      {{ config.copyrightTitle }}
    </a>
  </div>
</template>

<script setup>
import { ref, watch } from "vue";
import config from "@/config";
import Userinfo from "./components/userinfo.vue";
import SiderMenu from "./components/sider-menu.vue";
import { useUser } from "@/store/admin/user.js";
import { useMenu } from "@/store/admin/menu.js";
import { useTabs } from "@/store/admin/tabs.js";
import { useTheme } from "@/store/admin/theme.js";

const userStore = useUser();
const menuStore = useMenu();
const tabsStore = useTabs();
const themeStore = useTheme();

const showApps = () => {
  menuStore.showApps = !menuStore.showApps;
  if (menuStore.showApps) {
    menuStore.currentMenus = menuStore.menus;
    menuStore.currentMenuKey = menuStore.selectedKeys;
    menuStore.selectedKeys = menuStore.currentAppId;
  } else {
    menuStore.currentMenus = menuStore.getCurrentMenus;
    menuStore.selectedKeys = menuStore.currentMenuKey;
  }
};

const currentApp = ref(menuStore.getMenu(menuStore.getCurrentAppId));

watch(
  () => menuStore.getCurrentAppId,
  () => {
    currentApp.value = menuStore.getMenu(menuStore.getCurrentAppId);
  }
);
</script>
