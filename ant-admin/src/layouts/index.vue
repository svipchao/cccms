<template>
  <a-config-provider
    :theme="{
      algorithm: themeStore.darkTheme
        ? theme.darkAlgorithm
        : theme.defaultAlgorithm,
    }"
  >
    <a-layout
      :class="'cccms-layout ' + (themeStore.darkTheme ? 'dark' : 'default')"
    >
      <a-layout-sider
        :style="{ display: themeStore.getShowSider ? 'block' : 'none' }"
      >
        <Sider />
      </a-layout-sider>
      <a-layout>
        <a-layout-header v-show="!themeStore.contentFullscreen">
          <Header />
        </a-layout-header>
        <a-layout-content>
          <div class="cccms-tabs">
            <Tabs />
          </div>
          <router-view v-slot="{ Component }">
            <Transition duration="500" name="nested">
              <div class="cccms-content" v-if="!tabsStore.isRefresh">
                <keep-alive :include="tabsStore.getCacheTabs">
                  <component :is="Component" />
                </keep-alive>
              </div>
            </Transition>
          </router-view>
        </a-layout-content>
        <div
          class="cccms-mark"
          v-show="themeStore.showSider"
          @click="themeStore.switchShowSider()"
        />
      </a-layout>
    </a-layout>
  </a-config-provider>
</template>

<script setup>
import Header from "./header/index.vue";
import Sider from "./sider/index.vue";
import Tabs from "./content/tabs.vue";
import { useTabs } from "@/store/admin/tabs.js";
import { useTheme } from "@/store/admin/theme.js";
import { theme } from "ant-design-vue";

const tabsStore = useTabs();
const themeStore = useTheme();
</script>

<style lang="less">
.cccms-mark {
  top: 0;
  left: 0;
  width: 100vw;
  z-index: 998;
  position: fixed;
  height: 100vh;
  background-color: rgba(0, 0, 0, 0.15);
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
