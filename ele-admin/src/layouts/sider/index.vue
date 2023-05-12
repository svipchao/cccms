<template>
  <div class="el-aside-system">
    <el-space direction="vertical" fill>
      <el-row style="flex-flow: row nowrap">
        <Userinfo />
      </el-row>
      <el-space>
        <template #default>
          <el-divider direction="vertical" margin="4.5px" />
        </template>
        <el-button> button1 </el-button>
        <el-button> button1 </el-button>
      </el-space>
      <el-row style="align-items: center">
        <el-col flex="auto" style="text-align: center">
          <el-tooltip content="切换系统应用" position="tl">
            <el-button type="text" @click="showApps">
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
            </el-button>
          </el-tooltip>
        </el-col>
        <el-col flex="10px">
          <el-divider direction="vertical" margin="4.5px" />
        </el-col>
        <el-col flex="auto" style="text-align: center">
          <el-tooltip
            :content="themeStore.getTheme ? '切换亮色模式' : '切换暗黑模式'"
            position="tl"
          >
            <el-button type="text" @click="themeStore.switchTheme()">
              <template #icon>
                <i class="ri-moon-fill" style="color: #fff" v-if="themeStore.getTheme"></i>
                <i class="ri-sun-fill" style="color: #000" v-else></i>
              </template>
            </el-button>
          </el-tooltip>
        </el-col>
        <el-col flex="10px">
          <el-divider direction="vertical" margin="4.5px" />
        </el-col>
        <el-col flex="auto" style="text-align: center">
          <el-tooltip content="清楚缓存" position="tl">
            <el-button type="text" @click="userStore.setAccessToken()">
              <template #icon>
                <i class="ri-refresh-line"></i>
              </template>
            </el-button>
          </el-tooltip>
        </el-col>
        <el-col flex="10px">
          <el-divider direction="vertical" margin="4.5px" />
        </el-col>
        <el-col flex="auto" style="text-align: center">
          <el-tooltip content="注销登录" position="tl">
            <el-button type="text" @click="userStore.logout()">
              <template #icon>
                <i class="ri-logout-box-line"></i>
              </template>
            </el-button>
          </el-tooltip>
        </el-col>
      </el-row>
    </el-space>
    <el-divider />
  </div>
  <div class="el-aside-menu">
    <el-menu
      mode="vertical"
      :level-indent="10"
      accordion
      auto-open-selected
      :selected-keys="[menuStore.getSelectedKeys]"
      @menu-item-click="tabsStore.switchTab"
    >
      <sider-menu :menus="menuStore.currentMenus" v-if="menuStore.currentMenus !== undefined" />
      <el-empty v-else />
    </el-menu>
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
