<template>
  <div class="nodes-class">
    <a-tree
      v-if="props.nodes.length > 0"
      checkable
      default-expand-all
      :checked-keys="props.checkedNodes"
      :data="props.nodes"
      :fieldNames="{
        label: 'parentNode',
        key: 'currentNode',
        title: 'title',
        children: 'children',
      }"
      @check="selectNodeFun"
    />
    <a-empty v-else />
  </div>
</template>

<script setup>
const props = defineProps({
  id: undefined,
  nodes: undefined,
  checkedNodes: undefined,
});

const emit = defineEmits(['update:checkedNodes']);
const selectNodeFun = (nodes) => {
  emit('update:checkedNodes', nodes);
};
</script>

<style scoped lang="less">
.nodes-class {
  width: 100%;
  overflow: hidden;
}
:deep(.arco-tree) {
  overflow-y: auto;
  &::-webkit-scrollbar {
    width: 8px;
    height: 8px;
  }
  &::-webkit-scrollbar-track {
    border-radius: 8px;
  }
  &::-webkit-scrollbar-thumb {
    border-radius: 8px;
    background: var(--color-neutral-3);
  }
}
:deep(.arco-tree-node-is-leaf) {
  display: inline-flex;
}
</style>
