<template>
  <div v-for="menu in props.menus" :key="menu.id">
    <a-sub-menu
      v-if="menu.children && menu.children.length > 0 && !menuStore.showApps"
      :key="menu.id"
    >
      <template #title>
        <i :class="menu.icon"></i>
        {{ menu.name }}
      </template>
      <sider-menu :menus="menu.children" />
    </a-sub-menu>
    <template v-else>
      <a v-if="isLink(menu.url)" :href="menu.url" :target="menu.target">
        <a-menu-item>
          <i :class="menu.icon"></i>
          {{ menu.name }}
        </a-menu-item>
      </a>
      <a-menu-item v-else :key="menu.id">
        <router-link :to="menu.url" :target="menu.target">
          <i :class="menu.icon"></i>
          {{ menu.name }}
        </router-link>
      </a-menu-item>
    </template>
  </div>
</template>

<script setup>
import { isLink } from '@/utils/utils.js';
import { useMenuStore } from '@/stores/admin/menu.js';

const menuStore = useMenuStore();

const props = defineProps({
  menus: {},
});
</script>
