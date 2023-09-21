<template>
  <div :class="dark ? 'dark' : 'default'">
    <a-card size="small">
      <div class="cc-pane">
        <div
          class="cc-pane-left"
          :style="{
            marginLeft: siderShow ? '0px' : '-300px',
          }"
        >
          <slot name="left" />
          <span class="cc-pane-split" @click="siderSwitch">
            <i class="ri-arrow ri-arrow-left-s-fill"></i>
          </span>
        </div>
        <div class="cc-pane-right">
          <slot name="right" />
          <a-button @click="darkSwitch">切换</a-button>
          <a-table
            :dataSource="dataSource"
            :columns="columns"
            :scroll="{ x: 1500 }"
          />
        </div>
      </div>
    </a-card>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { theme } from 'ant-design-vue';

const dark = ref(false);
const darkSwitch = () => {
  dark.value = !dark.value;
};

const siderShow = ref(true);
const siderSwitch = () => {
  siderShow.value = !siderShow.value;
};

const dataSource = [
  {
    key: '1',
    name: '胡彦斌',
    age: 32,
    address: '西湖区湖底公园1号',
  },
];

const columns = [
  {
    title: '姓名',
    dataIndex: 'name',
    key: 'name',
    width: 200,
  },
  {
    title: '年龄',
    dataIndex: 'age',
    key: 'age',
    width: 200,
  },
  {
    title: '住址',
    dataIndex: 'address',
    key: 'address',
    width: 200,
  },
];
</script>

<style lang="less">
.cc-pane {
  height: 500px;
  display: flex;
  overflow: hidden;
  .cc-pane-left {
    flex: 0 0 300px;
    overflow: auto;
    padding: 12px;
    border-radius: 8px 0 0 8px;
    border: 1px solid var(--cc-border-color);
    border-right: 0px;
    .cc-pane-split {
      z-index: 200;
      margin-left: 288px;
      position: absolute;
      .ri-arrow-left-s-fill,
      .ri-arrow-right-s-fill {
        color: var(--cc-text-color);
        width: 14px;
        cursor: pointer;
        font-size: 12px;
        height: 30px;
        line-height: 30px;
        z-index: 997;
        display: block;
        border-radius: 0 4px 4px 0;
        background: var(--cc-background-color);
        border-top: 0px;
        border-left: 0px;
        &:hover {
          background: var(--cc-background-hover-color);
        }
      }
    }
  }
  .cc-pane-right {
    width: 300px;
    padding: 12px;
    flex: 1 1 auto;
    overflow: auto;
    border-radius: 0 8px 8px 0;
    border: 1px solid var(--cc-border-color);
  }
}
</style>
