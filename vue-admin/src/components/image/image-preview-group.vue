<template>
  <div>
    <a-image-preview-group
      infinite
      :visible="props.visible"
      v-model:current="currentImage"
      :srcList="props.list"
      @visible-change="visibleImage"
    />
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
const props = defineProps({
  current: 0,
  visible: false,
  list: undefined,
});

const currentImage = ref(0);

watch(
  () => props.current,
  (current) => {
    currentImage.value = props.current;
  }
);

const emit = defineEmits(['update:visible']);

const visibleImage = () => {
  emit('update:visible', false);
};
</script>
