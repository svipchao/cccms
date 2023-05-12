<template>
  <el-row class="header-custom">
    <el-col :span="12">
      <div class="header-custom-box">
        <el-button type="text" @click="themeStore.switchShowSider()">
          <template #icon>
            <i class="ri-menu-fold-line" v-if="themeStore.showSider"></i>
            <i class="ri-menu-unfold-line" v-else></i>
          </template>
        </el-button>
      </div>
    </el-col>
    <el-col :span="12" class="header-custom-right">
      <div class="header-custom-box">
        <el-tooltip :content="isFullscreen ? '退出全屏' : '全屏'" position="br">
          <el-button type="text" @click="fullscreen.toggle()">
            <template #icon>
              <i class="ri-fullscreen-exit-line" v-if="isFullscreen"></i>
              <i class="ri-fullscreen-line" v-else></i>
            </template>
          </el-button>
        </el-tooltip>
      </div>
    </el-col>
  </el-row>
</template>

<script setup>
import { ref } from "vue";
import { useTheme } from "@/store/admin/theme.js";
import fullscreen from "@/utils/fullscreen.js";

const themeStore = useTheme();

const isFullscreen = ref();

fullscreen.on("change", () => {
  isFullscreen.value = fullscreen.isFullscreen;
});
</script>
