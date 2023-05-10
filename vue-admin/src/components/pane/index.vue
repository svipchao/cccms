<template>
  {{ props.size }}
  <div class="cc-pane">
    <div
      class="cc-pane-left"
      :style="{
        width: props.size,
      }"
    >
      1
    </div>
    <div class="cc-pane-split">
      <div class="cc-pane-split-body">
        <i class="ri-arrow-left-s-fill"></i>
      </div>
    </div>
    <div class="cc-pane-right">3</div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from "vue";

const props = defineProps({
  size: "300px",
});
console.log(props.size);
</script>

<style scoped lang="less">
.cc-pane {
  display: flex;
  flex-wrap: wrap;
  height: 500px;
  padding: 10px;
  background-color: #fff;
  .cc-pane-left {
    border: 1px solid var(--color-neutral-3);
  }
  .cc-pane-split {
    width: 10px;
    display: flex;
    align-items: center;
    .cc-pane-split-body {
      width: 16px;
      height: 20px;
      line-height: 20px;
      margin-left: 10px;
      text-align: center;
      border-radius: 0 6px 6px 0;
      background: #ccc;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
      z-index: 100;
      .ri-arrow-left-s-fill {
        font-size: 16px;
      }
    }
  }
  .cc-pane-right {
    flex: auto;
    border: 1px solid var(--color-neutral-3);
  }
}
</style>
