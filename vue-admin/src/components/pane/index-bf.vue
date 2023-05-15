<template>
  <div class="cc-pane">
    <div class="cc-pane-box">
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
      <div class="cc-pane-split">
        <i class="ri-arrow ri-arrow-left-s-fill" @click="switchSizeFun" v-if="show"></i>
        <i class="ri-arrow ri-arrow-right-s-fill" @click="switchSizeFun" v-else></i>
      </div>
      <div
        class="cc-pane-right"
        :style="{
          border: data.rightBorder ? '1px solid var(--color-neutral-3)' : 'none',
          width: 'calc(100% - ' + data.size + ')',
        }"
      >
        <slot name="right" />
        <div class="cc-pane-mark" v-show="show" @click="switchSizeFun"></div>
      </div>
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

const switchSizeFun = () => {
  show.value = !show.value;
};
</script>

<style scoped lang="less">
.cc-pane {
  position: relative;
  box-sizing: border-box;
  .cc-pane-box {
    height: 100%;
    display: flex;
    overflow: hidden;
    position: relative;
    .cc-pane-left {
      height: 100%;
      position: relative;
      @media screen and (max-width: 930px) {
        z-index: 998;
        position: absolute;
      }
      & > div {
        width: calc(100% - 10px);
        height: 100%;
        padding: 10px;
        box-sizing: border-box;
        background: var(--color-bg-2);
      }
    }
    .cc-pane-split {
      margin-left: 10px;
      .ri-arrow-left-s-fill,
      .ri-arrow-right-s-fill {
        cursor: pointer;
        font-size: 14px;
        height: 35px;
        line-height: 35px;
        top: 1px;
        // right: -16px;
        z-index: 997;
        position: absolute;
        background: rgb(var(--gray-3));
        border-radius: 0 0px 6px 0;
        border: 1px solid var(--color-neutral-3);
        border-top: 0px;
        border-left: 0px;
        &:hover {
          z-index: 100;
          background: var(--color-bg-4);
        }
      }
      @media screen and (max-width: 930px) {
        z-index: 998;
        position: absolute;
      }
    }
    .cc-pane-right {
      flex: 1 1 auto;
      height: 100%;
      position: relative;
      box-sizing: border-box;
      .cc-pane-mark {
        width: 100%;
        height: 100%;
        top: 0;
        z-index: 996;
        position: absolute;
        box-sizing: border-box;
        background-color: rgba(0, 0, 0, 0.15);
        border: 1px solid var(--color-neutral-3);
        @media screen and (min-width: 930px) {
          display: none;
        }
      }
    }
  }
}
</style>
