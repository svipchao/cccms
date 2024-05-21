<template>
  <a-layout class="cccms-layout" v-waterMarker="watermark">
    <a-layout-sider
      :style="{ display: themeStore.getShowSider ? 'block' : 'none' }"
    >
      <Sider />
    </a-layout-sider>
    <a-layout>
      <a-layout-header v-show="!themeStore.contentFullscreen">
        <Header />
      </a-layout-header>
      <a-layout>
        <a-layout-content>
          <div class="cccms-tabs">
            <Tabs />
          </div>
          <router-view v-slot="{ Component }">
            <transition duration="500" name="nested">
              <div class="cccms-content" v-if="!tabsStore.isRefresh">
                <keep-alive :include="tabsStore.getCacheTabs">
                  <component :is="Component" />
                </keep-alive>
              </div>
            </transition>
          </router-view>
        </a-layout-content>
        <div
          class="cccms-mark"
          @click="themeStore.switchShowSider()"
          v-show="themeStore.showSider"
        />
      </a-layout>
    </a-layout>
  </a-layout>
</template>

<script setup>
import Header from './header/index.vue';
import Sider from './sider/index.vue';
import Tabs from './content/tabs.vue';
import { useTabsStore } from '@/store/admin/tabs.js';
import { useUserStore } from '@/store/admin/user.js';
import { useThemeStore } from '@/store/admin/theme.js';

const tabsStore = useTabsStore();
const userStore = useUserStore();
const themeStore = useThemeStore();

const watermark = {
  open: userStore.configs?.watermark?.open == 1,
  text: `@ID:${userStore.id} ${userStore.nickname}(${userStore.username})`,
};
</script>

<style lang="less">
.cccms-mark {
  top: 0;
  left: 0;
  width: 100vw;
  z-index: 998;
  position: fixed;
  height: 100vh;
  background-color: rgba(var(--gray-1), 0.3);
  backdrop-filter: blur(5px);
  @media screen and (min-width: 930px) {
    display: none;
  }
}
.nested-enter-active,
.nested-leave-active {
  transition: all 0.3s ease-in-out;
}
.nested-leave-active {
  transition-delay: 0.25s;
}
.nested-enter-from,
.nested-leave-to {
  transform: translateX(30px);
  opacity: 0;
}
</style>
