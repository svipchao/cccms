<template>
  <a-tabs
    v-model:activeKey="activeKey"
    tab-position="top"
    @tabScroll="callback"
  >
    <a-tab-pane v-for="i in 30" :key="i" :tab="`Tab-${i}`" />
  </a-tabs>
</template>
<script setup>
import { ref } from 'vue';

const activeKey = ref();
const callback = () => {
  console.log(123);
};
</script>
