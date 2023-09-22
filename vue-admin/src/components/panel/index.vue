<template>
  <div class="cc-panel">
    <div
      class="cc-panel-left"
      :style="{
        width: data.size,
        flex: '0 0 ' + data.size,
        marginLeft: show ? '0px' : '-' + data.size,
      }"
    >
      123
    </div>
    <div
      class="cc-panel-split"
      @click="switchSiderFun"
      :style="{
        marginLeft: show ? 'calc(' + data.size + ' - 2px)' : '10px',
      }"
    >
      <i class="ri-arrow-left-double-line" v-if="show"></i>
      <i class="ri-arrow-right-double-line" v-else></i>
    </div>
    <div
      class="cc-panel-right"
      :style="{
        paddingLeft: show ? '10px' : '20px',
      }"
    >
      <a-button type="primary" long>Primary</a-button>
    </div>
    <div class="cc-panel-mark" @click="switchSiderFun" v-show="show"></div>
  </div>
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
  show.value = !show.value;
};
</script>
<style lang="less">
.cc-panel {
  position: relative;
  box-sizing: border-box;
  background-color: #fff;
  display: flex;
  height: 100%;
  overflow: hidden;
  .cc-panel-left {
    flex: 0 0 300px;
    padding: 10px;
    height: 100%;
    background-color: #fff;
    @media screen and (max-width: 930px) {
      z-index: 900;
      position: absolute;
    }
  }
  .cc-panel-split {
    width: 1px;
    margin: 10px 10px;
    border: 1px solid #f2f2f2;
    padding: 30px 0;
    height: calc(100% - 20px);
    z-index: 900;
    position: absolute;
    i {
      cursor: pointer;
      color: #666;
      font-size: 14px;
      width: 20px;
      height: 20px;
      line-height: 18px;
      display: block;
      text-align: center;
      background-color: #fff;
      border-radius: 10px;
      margin: 10px 0 0 -10px;
      border: 1px solid #f2f2f2;
    }
  }
  .cc-panel-right {
    flex: 1 1 auto;
    padding: 10px;
  }
  .cc-panel-mark {
    width: 100%;
    height: 100%;
    position: absolute;
    left: 0;
    z-index: 899;
    background-color: rgba(0, 0, 0, 0.15);
    @media screen and (min-width: 930px) {
      display: none;
    }
  }
}
</style>
