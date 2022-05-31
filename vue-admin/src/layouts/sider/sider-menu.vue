<template>
  <div v-for="menu in props.menus" :key="menu.id">
    <a-sub-menu v-if="menu.children && menu.children.length > 0" :key="menu.id">
      <template #title>
        <i class="iconfont" :class="menu.icon"></i>
        {{ menu.name }}
      </template>
      <sider-menu :menus="menu.children" />
    </a-sub-menu>
    <template v-else>
      <a v-if="isLink(menu.url)" :href="menu.url" :target="'_blank'">
        <a-menu-item :key="menu.id">
          <i class="iconfont" :class="menu.icon"></i>
          {{ menu.name }}
        </a-menu-item>
      </a>
      <router-link v-else :to="'/' + menu.url">
        <a-menu-item :key="menu.id">
          <i class="iconfont" :class="menu.icon"></i>
          {{ menu.name }}
        </a-menu-item>
      </router-link>
    </template>
  </div>
</template>

<script setup>
import { isLink } from "@/utils/utils";
const props = defineProps({
  menus: {},
});
</script>
