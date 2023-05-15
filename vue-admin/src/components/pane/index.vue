<template>
  <div class="cc-pane">
    <div
      class="cc-pane-left"
      :style="{
        width: data.size,
        flex: '0 0 ' + data.size,
        marginLeft: show ? '0px' : '-' + data.size,
      }"
    >
      <div
        :style="{
          border: data.leftBorder ? '1px solid var(--color-neutral-3)' : 'none',
        }"
      >
        <slot name="left" />
      </div>
    </div>
    <div
      class="cc-pane-right"
      :style="{
        border: data.rightBorder ? '1px solid var(--color-neutral-3)' : 'none',
        width: 'calc(100% - ' + data.size + ')',
      }"
    >
      <slot name="right" />
      <div class="cc-pane-mark" v-show="show" />
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from "vue";

const props = defineProps({
  size: undefined,
  leftBorder: undefined,
  rightBorder: undefined,
});

const data = reactive({
  size: "300px",
  leftBorder: true,
  rightBorder: true,
});
onMounted(() => {
  if (props.size !== undefined) data.size = props.size;
  if (props.leftBorder !== undefined) data.leftBorder = props.leftBorder;
  if (props.rightBorder !== undefined) data.rightBorder = props.rightBorder;
});

const show = ref(true);

const switchSplitFun = () => {
  show.value = !show.value;
};
</script>

<style scoped lang="less">
.cc-pane {
  display: flex;
  .cc-pane-left {
    flex: 0 0 300px;
    position: relative;
    border-right: 0px;
    & > div {
      width: calc(100% - 10px);
      height: 100%;
      padding: 10px;
      background: var(--color-bg-2);
    }
  }
  .cc-pane-right {
    flex-grow: 1;
    position: relative;
    overflow: hidden;
    .cc-pane-mark {
      width: 100%;
      height: 100%;
      top: 0;
      z-index: 996;
      position: absolute;
      background-color: rgba(0, 0, 0, 0.15);
      border: 1px solid var(--color-neutral-3);
      @media screen and (min-width: 930px) {
        display: none;
      }
    }
  }
}
</style>
