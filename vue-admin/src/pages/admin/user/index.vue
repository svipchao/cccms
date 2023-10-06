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
import datas from '../../../components/icons/icon.json';

const showIcon = ref(true);
const icon = ref('ri-home-line');
let aa = datas;
function demo() {
  for (let i in aa) {
    for (let j in aa[i]) {
      if (aa[i][j].indexOf('333') == -1) {
        delete aa[i][j];
        // aa[i].splice(j, 1);
      }
      console.log(j);
    }
  }
  console.log(aa);
}
// demo();
</script>
