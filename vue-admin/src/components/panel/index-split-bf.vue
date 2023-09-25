<template>
  <a-split v-model:size="data.size" class="cc-panel">
    <template #first>
      <div class="cc-panel-left">
        <slot name="left" />
      </div>
    </template>
    <template #resize-trigger>
      <div class="cc-panel-split" @click="switchSiderFun">
        <i class="ri-arrow-left-double-line" v-if="show"></i>
        <i class="ri-arrow-right-double-line" v-else></i>
      </div>
    </template>
    <template #second>
      <div class="cc-panel-right">
        <slot name="right" />
      </div>
      <div class="cc-panel-mark" @click="switchSiderFun" v-show="show"></div>
    </template>
  </a-split>
</template>
<script setup>
import { ref, reactive, onMounted, watch } from "vue";

const props = defineProps({
  size: undefined,
});

const data = reactive({
  size: "300px",
});

onMounted(() => {
  if (props.size !== undefined) data.size = props.size;
});

const show = ref(true);

const switchSiderFun = () => {
  if (show.value) {
    data.size = "0px";
  } else {
    data.size = props.size;
  }
  show.value = !show.value;
};
</script>
<style lang="less">
.cc-panel {
  display: flex;
  width: 100%;
  height: calc(100vh - 110px);
  overflow: hidden;
  padding: 10px;
  position: relative;
  box-sizing: border-box;
  background-color: var(--color-bg-1);
  .arco-split-pane-first {
    z-index: 900;
    height: 100%;
    background-color: var(--color-bg-1);
    &::-webkit-scrollbar {
      width: 0;
      height: 0;
    }
  }
  .arco-split-trigger {
    z-index: 900;
    width: 0px;
    height: 100%;
    background-color: var(--color-bg-1);
    border: 1px solid var(--color-border);
    margin: 0 10px;
    i {
      cursor: pointer;
      color: #666;
      font-size: 14px;
      width: 20px;
      height: 20px;
      line-height: 18px;
      display: block;
      text-align: center;
      background-color: var(--color-bg-1);
      border-radius: 10px;
      margin: 30px 0 0 -10px;
      box-shadow: 0 2px 3px #0000000a;
      border: 1px solid var(--color-border);
    }
  }
  .arco-split-pane-second {
    .cc-panel-right {
      width: 100%;
      height: 100%;
      overflow: auto;
    }
    &::-webkit-scrollbar {
      width: 0;
      height: 0;
    }
  }
  .cc-panel-mark {
    width: calc(100% - 20px);
    height: calc(100% - 20px);
    position: absolute;
    margin: 10px;
    top: 0;
    left: 0;
    z-index: 899;
    background-color: rgba(0, 0, 0, 0.15);
    @media screen and (min-width: 930px) {
      display: none;
    }
  }
}
</style>
