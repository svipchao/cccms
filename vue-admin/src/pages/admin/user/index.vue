<template>
  <CPanel size="300px">
    <template #left>123</template>
    <template #right>
      <div style="height: 250px; border: 1px solid red"></div>
    </template>
  </CPanel>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import CPanel from '@/components/panel/index.vue';
</script>
