<template>
  <div class="arco-layout-sider-system">
    <a-space direction="vertical" fill>
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
          <a-divider direction="vertical" margin="4.5px" />
        </a-col>
        <a-col flex="auto" style="text-align: center">
          <a-tooltip
            :content="themeStore.getTheme ? '切换亮色模式' : '切换暗黑模式'"
            position="tl"
          >
            <a-button type="text" @click="themeStore.switchTheme()">
              <template #icon>
                <i
                  class="ri-moon-line"
                  style="color: #fff"
                  v-if="themeStore.getTheme"
                ></i>
                <i class="ri-sun-line" style="color: #000" v-else></i>
              </template>
            </a-button>
          </a-tooltip>
        </a-col>
        <a-col flex="10px">
          <a-divider direction="vertical" margin="4.5px" />
        </a-col>
        <a-col flex="auto" style="text-align: center">
          <a-tooltip content="清除缓存" position="tl">
            <a-button type="text" @click="userStore.setAccessToken(true)">
              <template #icon>
                <i class="ri-refresh-line"></i>
              </template>
            </a-button>
          </a-tooltip>
        </a-col>
        <a-col flex="10px">
          <a-divider direction="vertical" margin="4.5px" />
        </a-col>
        <a-col flex="auto" style="text-align: center">
          <Popconfirm
            content="确定要注销吗？"
            type="warning"
            position="left"
            @ok="userStore.logout()"
          >
            <a-tooltip content="注销登录" position="tl">
              <a-button type="text">
                <template #icon>
                  <i class="ri-logout-box-line"></i>
                </template>
              </a-button>
            </a-tooltip>
          </Popconfirm>
        </a-col>
      </a-row>
    </a-space>
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
      <SiderMenu
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
import { ref, watch } from 'vue';
import config from '@/config';
import Userinfo from './components/userinfo.vue';
import SiderMenu from './components/sider-menu.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import { useUserStore } from '@/stores/admin/user.js';
import { useMenuStore } from '@/stores/admin/menu.js';
import { useTabsStore } from '@/stores/admin/tabs.js';
import { useThemeStore } from '@/stores/admin/theme.js';

const userStore = useUserStore();
const menuStore = useMenuStore();
const tabsStore = useTabsStore();
const themeStore = useThemeStore();

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
