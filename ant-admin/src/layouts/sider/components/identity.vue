<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    title="切换身份信息"
    @cancel="okModal"
    @ok="okModal"
  >
    <a-space>
      <a-select
        v-model="province"
        popup-visible
        :triggerProps="{
          popupVisible: true,
          popupStyle: {
            border: '1px solid red',
          },
        }"
      >
        <a-option v-for="value of Object.keys(data)">{{ value }}</a-option>
      </a-select>
      <a-select popup-visible :options="data[province] || []" v-model="city" />
    </a-space>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { assignObject } from '@/utils/utils.js';

const props = defineProps({
  visible: false,
});

const province = ref('Sichuan');
const city = ref('Chengdu');
const data = {
  Beijing: ['Haidian', 'Chaoyang', 'Changping'],
  Sichuan: ['Chengdu', 'Mianyang', 'Aba'],
  Guangdong: ['Guangzhou', 'Shenzhen', 'Shantou'],
};
watch(province, () => {
  city.value = '';
});

const emit = defineEmits(['update:visible']);

const okModal = async () => {
  emit('update:visible', false);
};
</script>
