<template>
  <a-layout class="cccms-layout">
    <a-layout-sider :style="{ display: themeStore.getShowSider ? 'block' : 'none' }">
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
          <div class="cccms-content">
            <router-view v-slot="{ Component }">
              <Transition duration="500" name="nested">
                <div v-if="!tabsStore.isRefresh">
                  <keep-alive :include="tabsStore.getCacheTabs">
                    <component :is="Component" />
                  </keep-alive>
                </div>
              </Transition>
            </router-view>
          </div>
        </a-layout-content>
        <Mark @click="themeStore.switchShowSider()" v-show="themeStore.showSider" />
      </a-layout>
    </a-layout>
  </a-layout>
</template>

<script setup>
import Header from "./header/index.vue";
import Sider from "./sider/index.vue";
import Tabs from "./content/tabs.vue";
import Mark from "@/components/mark/index.vue";
import { useTabs } from "@/store/admin/tabs.js";
import { useTheme } from "@/store/admin/theme.js";

const tabsStore = useTabs();
const themeStore = useTheme();
</script>

<style>
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
