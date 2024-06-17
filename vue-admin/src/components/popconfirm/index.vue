<template>
  <a-popconfirm
    :okLoading="okLoadingStatus"
    :ok-button-props="okButtonPropsObj"
    v-bind="$attrs"
    @popup-visible-change="changePopconfirm"
  >
    <template v-for="(_, name) in $slots" v-slot:[name]="data">
      <slot :name="name" v-bind="data" />
    </template>
  </a-popconfirm>
</template>

<script setup>
import { ref, reactive, useAttrs, onMounted } from 'vue';
import config from '@/config';
const attrs = useAttrs();

const props = defineProps({
  okLoadingTime: undefined,
});

const okButtonPropsObj = ref();

onMounted(() => {
  // 更新弹窗按钮状态
  if (attrs['ok-button-props'] === undefined) {
    okButtonPropsObj.value = {
      status: attrs.type,
    };
  }
});

const okLoadingStatus = ref(false);

const changePopconfirm = (visible) => {
  let loadingTime = 0;
  if (props.okLoadingTime === undefined) {
    loadingTime = config.okLoadingTime;
  } else {
    loadingTime = props.okLoadingTime;
  }
  if (props.okLoadingTime !== 0) {
    if (visible) {
      okLoadingStatus.value = true;
      setTimeout(() => {
        okLoadingStatus.value = false;
      }, loadingTime);
    } else {
      okLoadingStatus.value = true;
    }
  }
};
</script>
