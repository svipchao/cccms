<template>
  <CIcon v-model:visible="showIcon" :data="icon"></CIcon>
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
import CIcon from '@/components/icons/index.vue';
import data from '@arco-design/web-vue';

console.log(data);

const showIcon = ref(false);
const icon = ref('ri-home-line');
</script>
