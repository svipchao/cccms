<template>
  <h1>{{ userStore.count }}</h1>
  <a-button @click="demo()">增加</a-button>
  <div style="height: 1800px; border: 1px solid red">123</div>
</template>

<script setup>
import { useUserStore } from '@/stores/modules/admin/user.js';
const userStore = useUserStore();

const demo = () => {
  userStore.inc();
};
</script>
