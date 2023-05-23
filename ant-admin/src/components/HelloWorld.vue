<template>
  <div :class="dark ? 'dark' : 'default'">
    <a-config-provider
      :theme="{
        algorithm: dark ? theme.defaultAlgorithm : theme.darkAlgorithm,
      }"
    >
      <div class="cc-pane">
        <div
          class="cc-pane-left"
          :style="{
            marginLeft: siderShow ? '0px' : '-300px',
          }"
        >
          <span class="cc-pane-split" @click="siderSwitch">
            <i class="ri-arrow ri-arrow-left-s-fill"></i>
          </span>
        </div>
        <div class="cc-pane-right">
          <a-button @click="darkSwitch">切换</a-button>
          <a-table :dataSource="dataSource" :columns="columns" :scroll="{ x: 1500 }" />
        </div>
      </div>
    </a-config-provider>
  </div>
</template>

<script setup>
import { ref, reactive } from "vue";
import { theme } from "ant-design-vue";

const dark = ref(true);
const darkSwitch = () => {
  dark.value = !dark.value;
};

const siderShow = ref(true);
const siderSwitch = () => {
  siderShow.value = !siderShow.value;
};

const dataSource = [
  {
    key: "1",
    name: "胡彦斌",
    age: 32,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
  {
    key: "2",
    name: "胡彦祖",
    age: 42,
    address: "西湖区湖底公园1号",
  },
];

const columns = [
  {
    title: "姓名",
    dataIndex: "name",
    key: "name",
    width: 200,
  },
  {
    title: "年龄",
    dataIndex: "age",
    key: "age",
    width: 200,
  },
  {
    title: "住址",
    dataIndex: "address",
    key: "address",
    width: 200,
  },
];
</script>

<style lang="less">
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
.cc-pane {
  overflow: hidden;
  display: flex;
  height: 500px;
  .cc-pane-left {
    flex: 0 0 300px;
    // width: 300px;
    // flex-shrink: 0;
    // flex-grow: 0;
    border: 1px solid var(--cc-color);
    border-right: 0px;
    .cc-pane-split {
      width: 1px;
      left: 299px;
      z-index: 200;
      position: relative;
      height: 40px;
      .ri-arrow-left-s-fill,
      .ri-arrow-right-s-fill {
        // color: #f1f1f1;
        width: 20px;
        cursor: pointer;
        font-size: 14px;
        height: 30px;
        line-height: 30px;
        z-index: 997;
        border-radius: 0 6px 6px 0;
        display: block;
        background: var(--cc-color);
        border-top: 0px;
        border-left: 0px;
        &:hover {
          z-index: 100;
        }
      }
    }
  }
  .cc-pane-right {
    flex: 1 1 auto;
    position: relative;
    min-width: 300px;
    border: 1px solid var(--cc-color);
    overflow: auto;
    .cc-pane-mark {
      background: rgba(0, 0, 0, 0.3);
      width: 100%;
      height: 100%;
      top: 0;
      bottom: 0;
      position: absolute;
      overflow: hidden;
      @media screen and (min-width: 930px) {
        display: none;
      }
    }
  }
}
</style>
