<template>
  <a-row class="cc-pane">
    <a-col flex="300px" class="cc-pane-left">
      <div class="cc-pane-split">
        <i class="ri-arrow ri-arrow-left-s-fill"></i>
      </div>
    </a-col>
    <a-col flex="auto" class="cc-pane-right">auto</a-col>
  </a-row>
</template>

<script setup>
import { ref } from "vue";
</script>

<style lang="less">
*,
*:before,
*:after {
  box-sizing: border-box;
}
body {
  background: #f2f2f2;
  padding: 10px;
}
.cc-pane {
  background: #fff;
  height: 500px;
  overflow: hidden;
}
.cc-pane-left {
  border: 1px solid red;
  // margin-left: -300px;
  .cc-pane-split {
    width: 10px;
    height: 100%;
    right: -11px;
    position: absolute;
    border: 1px solid blue;
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
}
</style>
