<template>
  <div class="cc-pane">
    <div class="cc-pane-left"></div>
    <div class="cc-pane-right"></div>
  </div>
</template>

<script setup></script>

<style lang="less">
.cc-pane {
  display: flex;
  height: 500px;
  border: 1px solid red;
  .cc-pane-left {
    flex: 0 0 300px;
    border: 1px solid blue;
  }
  .cc-pane-right {
    flex: 1 1 auto;
    width: 300px;
    border: 1px solid var(--cc-color);
  }
}
.dark {
  --cc-color: #d9d9d9;
}
.default {
  --cc-color: #424242;
}
*,
*:before,
*:after {
  box-sizing: border-box;
}
body {
  background: #f2f2f2;
  padding: 10px;
}
</style>
