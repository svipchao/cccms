<template>
  <a-row class="header-custom">
    <a-col :span="6">
      <div class="header-custom-box">
        <a-button type="text" @click="themeStore.switchShowSider()">
          <template #icon>
            <i class="ri-menu-fold-line" v-if="themeStore.showSider"></i>
            <i class="ri-menu-unfold-line" v-else></i>
          </template>
        </a-button>
      </div>
    </a-col>
    <a-col :flex="12">
      <div class="wrap">
        <div class="cont">
          <p class="txt">
            开发迭代阶段 如有页面打不开或按钮点不了
            请先点击界面左上角清除缓存后在执行Ctrl+F5刷新浏览器
          </p>
        </div>
      </div>
    </a-col>
    <a-col :span="6" class="header-custom-right">
      <div class="header-custom-box">
        <a-tooltip :content="isFullscreen ? '退出全屏' : '全屏'" position="br">
          <a-button type="text" @click="fullscreen.toggle()">
            <template #icon>
              <i class="ri-fullscreen-exit-line" v-if="isFullscreen"></i>
              <i class="ri-fullscreen-line" v-else></i>
            </template>
          </a-button>
        </a-tooltip>
      </div>
    </a-col>
  </a-row>
</template>

<script setup>
import { ref } from 'vue';
import { useThemeStore } from '@/stores/admin/theme.js';
import fullscreen from '@/utils/fullscreen.js';

const themeStore = useThemeStore();

const isFullscreen = ref();

fullscreen.on('change', () => {
  isFullscreen.value = fullscreen.isFullscreen;
});
</script>

<style scoped>
* {
  box-sizing: border-box;
}
.wrap {
  position: relative;
  width: 100%;
  height: 32px;
  font-size: 0;
  overflow: hidden;
}
.wrap .cont {
  position: absolute;
  top: 0;
  left: 0;
  width: 200%;
  height: 32px;
  -webkit-animation: 5s move infinite linear;
}
.wrap .txt {
  font-size: 16px;
  display: inline-block;
  width: 100%;
  height: 32px;
  line-height: 32px;
  text-align: center;
  margin: 0px;
}
.wrap:hover .cont {
  -webkit-animation-play-state: paused;
}

@-webkit-keyframes move {
  /* 原理 left值的变化 移动一个容器的宽度 */
  0% {
    left: 0;
  }
  100% {
    left: -100%;
  }
}
</style>
