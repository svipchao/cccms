<template>
  <a-row class="header-custom">
    <a-col :span="12">
      <div class="header-custom-box">
        <a-button type="text" @click="themeStore.switchShowSider()">
          <template #icon>
            <i class="ri-menu-fold-line" v-if="themeStore.showSider"></i>
            <i class="ri-menu-unfold-line" v-else></i>
          </template>
        </a-button>
      </div>
    </a-col>
    <a-col :span="12" class="header-custom-right">
      <div class="header-custom-box">
        <a-button type="text" @click="fullscreen.toggle()">
          <template #icon>
            <i class="ri-fullscreen-exit-line" v-if="isFullscreen"></i>
            <i class="ri-fullscreen-line" v-else></i>
          </template>
        </a-button>
      </div>
    </a-col>
  </a-row>
</template>

<script setup>
import { ref } from 'vue';
import { useTheme } from '@/store/admin/theme.js';
import fullscreen from '@/utils/fullscreen.js';

const themeStore = useTheme();

const isFullscreen = ref();

fullscreen.on('change', () => {
  isFullscreen.value = fullscreen.isFullscreen;
});
</script>
