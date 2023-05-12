<template>
  <el-tabs
    :editable="tabsStore.tabs.length !== 1"
    hide-content
    :active-key="menuStore.getSelectedKeys"
    @tab-click="tabsStore.switchTab"
    @delete="tabsStore.closeTab"
  >
    <el-tab-pane v-for="tab in tabsStore.tabs" :key="tab.id">
      <template #title>
        <i :class="tab.icon"></i>
        <span v-show="tab.icon">&nbsp;</span>
        <span>{{ tab.name }}</span>
      </template>
    </el-tab-pane>
    <template #extra>
      <span class="tabs-icon-box" @click="tabsStore.refreshTab()">
        <i class="ri-refresh-line tabs-icon"></i>
      </span>
      <el-dropdown>
        <span class="tabs-icon-box">
          <i class="ri-more-line tabs-icon"></i>
        </span>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item @click="themeStore.switchContentFullscreen">
              <span v-if="themeStore.contentFullscreen">
                <i class="ri-fullscreen-exit-line" style="color: #000"></i>
                <span> 退出全屏</span>
              </span>
              <span v-else>
                <i class="ri-fullscreen-line"></i>
                <span> 内容全屏</span>
              </span>
            </el-dropdown-item>
            <el-dropdown-item @click="tabsStore.closeLeftTab">
              <i class="ri-arrow-left-line"></i>
              <span> 关闭左侧</span>
            </el-dropdown-item>
            <el-dropdown-item @click="tabsStore.closeRightTab">
              <i class="ri-arrow-right-line"></i>
              <span> 关闭右侧</span>
            </el-dropdown-item>
            <el-dropdown-item @click="tabsStore.closeOtherTab">
              <i class="ri-indeterminate-circle-line"></i>
              <span> 关闭其他</span>
            </el-dropdown-item>
            <el-dropdown-item @click="tabsStore.closeAllTab">
              <i class="ri-close-circle-line"></i>
              <span> 关闭全部</span>
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </template>
  </el-tabs>
</template>

<script setup>
import { useTabs } from "@/store/admin/tabs.js";
import { useMenu } from "@/store/admin/menu.js";
import { useTheme } from "@/store/admin/theme.js";

const tabsStore = useTabs();
const menuStore = useMenu();
const themeStore = useTheme();
</script>
