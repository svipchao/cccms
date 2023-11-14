<template>
  <a-tabs
    default-active-key="0"
    @change="changeType"
    hide-content
    editable
    show-add-button
  >
    <a-tab-pane
      v-for="(type, index) in props.types"
      :key="index"
      :title="type[props.fieldNames.title]"
    />
  </a-tabs>
</template>

<script setup>
import { onUpdated } from 'vue';

// 接收参数
const props = defineProps({
  key: 0,
  types: undefined, // 所有类别数据
  fieldNames: { key: 'key', title: 'title' },
});

const emits = defineEmits(['reload', 'update:key']);

onUpdated(() => {
  if (props.key === 0 && props.types.length > 0) {
    emits('update:key', props.types[0][props.fieldNames.key] || 0);
  }
});

const changeType = (key) => {
  console.log(props)
  console.log(props.types[key][props.fieldNames.key])
  emits('update:key', props.types[key][props.fieldNames.key]);
  emits('reload');
};
</script>

<style scoped>
.arco-tabs {
  padding-bottom: 10px !important;
}
</style>
