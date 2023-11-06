<template>
  <a-watermark :content="watermarkText" :zIndex="99999" :gap="[50, 50]">
    <a-layout class="cccms-layout">
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
            @click="themeStore.switchShowSider()"
            v-show="themeStore.showSider"
          />
        </a-layout>
      </a-layout>
    </a-layout>
  </a-watermark>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import Header from './header/index.vue';
import Sider from './sider/index.vue';
import Tabs from './content/tabs.vue';
import { useTabs } from '@/store/admin/tabs.js';
import { useUser } from '@/store/admin/user.js';
import { useTheme } from '@/store/admin/theme.js';
import { timestamp } from '@/utils/time.js';

const tabsStore = useTabs();
const userStore = useUser();
const themeStore = useTheme();

const watermarkText = [
  `@ID:${userStore.id} ${userStore.nickname}(${userStore.username})`,
  timestamp(),
];
// const water = reactive({
//   text: '',
// });
// water.text = `@ID:${userStore.id} ${userStore.nickname}(${userStore.username})`;
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
