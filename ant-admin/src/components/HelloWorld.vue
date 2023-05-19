<template>
  <a-config-provider
    :theme="{
      algorithm: dark ? theme.defaultAlgorithm : theme.darkAlgorithm,
      token: {
        colorPrimary: '#1890ff',
      },
    }"
  >
    <a-button />
    <span style="color: var(--ant-color-primary)">var(`--ant-color-primary`)</span>
    <a-row class="cc-pane" :style="{ backgroundColor: token.ccColor }">
      <a-col flex="300px" class="cc-pane-left">
        <div class="cc-pane-split" style="background: var(--ant-primary-color)">
          <i class="ri-arrow ri-arrow-left-s-fill"></i>
        </div>
      </a-col>
      <a-col flex="auto" class="cc-pane-right">
        <a-button @click="darkSwitch">切换</a-button>
      </a-col>
    </a-row>
  </a-config-provider>
</template>

<script setup>
import { ref, reactive } from "vue";
import { ConfigProvider, theme } from "ant-design-vue";
const { useToken } = theme;
const { token } = useToken();
console.log(token);

const colorState = reactive({
  ccColor: "red",
});

const dark = ref(false);
const darkSwitch = () => {
  dark.value = !dark.value;
  colorState.ccColor = "blue";
  ConfigProvider.config({
    theme: colorState,
  });
};
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
  border: 1px solid var(--ant-primary-color);
  // margin-left: -300px;
  .cc-pane-split {
    width: 10px;
    height: 100%;
    right: -11px;
    position: absolute;
    border: 1px solid blue;
    background: rgb(var(--primary-color));
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
      background: rgb(var(--primary-color));
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
