<template>
  <a-tabs default-active-key="0" @change="changeType" hide-content>
    <a-tab-pane
      v-for="(type, index) in props.types"
      :key="index"
      :title="type.name"
    />
  </a-tabs>
</template>

<script setup>
import { onUpdated } from "vue";
// 接收参数
const props = defineProps({
  type_id: 0,
  types: undefined, // 所有类别数据
});

const emits = defineEmits(["reload", "update:type_id"]);

onUpdated(() => {
  if (props.type_id === 0 && props.types.length > 0) {
    emits("update:type_id", props.types[0]["id"] || 0);
  }
});

const changeType = (key) => {
  emits("update:type_id", props.types[key]["id"]);
  emits("reload");
};
</script>

<style scoped>
.arco-tabs {
  padding-bottom: 10px !important;
}
</style>
