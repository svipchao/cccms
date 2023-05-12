<template>
  <div v-for="menu in props.menus" :key="menu.id">
    <el-sub-menu
      v-if="menu.children && menu.children.length > 0 && !menuStore.showApps"
      :key="menu.id"
    >
      <template #title>
        <i :class="menu.icon"></i>
        {{ menu.name }}
      </template>
      <sider-menu :menus="menu.children" />
    </el-sub-menu>
    <template v-else>
      <a v-if="isLink(menu.url)" :href="menu.url" :target="'_blank'">
        <el-menu-item :key="menu.id">
          <i :class="menu.icon"></i>
          {{ menu.name }}
        </el-menu-item>
      </a>
      <el-menu-item v-else :key="menu.id">
        <i :class="menu.icon"></i>
        {{ menu.name }}
      </el-menu-item>
    </template>
  </div>
</template>

<script setup>
import { isLink } from "@/utils/utils";
import { useMenu } from "@/store/admin/menu.js";

const menuStore = useMenu();

const props = defineProps({
  menus: {},
});
</script>
