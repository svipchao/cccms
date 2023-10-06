<template>
  <a-drawer
    title="选择图标"
    width="60vw"
    unmountOnClose
    :visible="visible"
    :mask-closable="false"
    :drawer-style="{
      minWidth: '300px',
    }"
    @ok="okDrawer"
    @cancel="okDrawer"
  >
    <div class="icons">
      <div class="icons-search">
        <a-input-search
          size="large"
          placeholder="请输入关键词进行搜索图标"
          button-text="搜索图标"
          search-button
          @search="searchIcons"
        />
        <div class="icons-tip">{{ iconTip }}</div>
      </div>
      <div class="icons-body">
        <a-row v-for="(data, dataK) in iconData">
          <template v-if="dataK == 'Editor'">
            <a-col :span="24" class="icon-title">
              <a-divider orientation="left">{{ dataK }}</a-divider>
            </a-col>
            <a-col
              :xs="8"
              :sm="6"
              :md="6"
              :lg="4"
              :xl="3"
              :xxl="2"
              v-for="(d, dK) in data"
            >
              <div class="icon-item">
                <div
                  @click="setCurrentIconData(`ri-${dK}`)"
                  @mouseover="setCurrentHoverData(dK, d)"
                  :class="[currentIcon == `ri-${dK}` ? 'icon-current' : '']"
                >
                  <i :class="`ri-${dK}`"></i>
                  <span>{{ dK }}</span>
                </div>
              </div>
            </a-col>
          </template>
          <template v-else>
            <a-col :span="24" class="icon-title">
              <a-divider orientation="left">{{ dataK }}</a-divider>
            </a-col>
            <a-col
              :xs="8"
              :sm="6"
              :md="6"
              :lg="4"
              :xl="3"
              :xxl="2"
              v-for="(d, dK) in data"
            >
              <div class="icon-item">
                <div
                  @click="setCurrentIconData(`ri-${dK}-line`)"
                  @mouseover="setCurrentHoverData(dK, d)"
                  :class="[
                    currentIcon == `ri-${dK}-line` ? 'icon-current' : '',
                  ]"
                >
                  <i :class="`ri-${dK}-line`"></i>
                  <span>{{ dK }}-line</span>
                </div>
                <div
                  @click="setCurrentIconData(`ri-${dK}-fill`)"
                  @mouseover="setCurrentHoverData(dK, d)"
                  :class="[
                    currentIcon == `ri-${dK}-fill` ? 'icon-current' : '',
                  ]"
                >
                  <i :class="`ri-${dK}-fill`"></i>
                  <span>{{ dK }}-fill</span>
                </div>
              </div>
            </a-col>
          </template>
        </a-row>
      </div>
    </div>
  </a-drawer>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue';
import datas from './icon.json';
import { Message } from '@arco-design/web-vue';
import { deepClone } from '@/utils/utils.js';

const props = defineProps({
  visible: false,
  icon: undefined,
});

const okDrawer = async () => {
  emit('update:visible');
};

const iconTip = ref('');
const setCurrentHoverData = (value, keyword) => {
  iconTip.value = value + '——' + keyword;
};

const iconData = ref(datas);
const searchIcons = (searchWord) => {
  let temporaryData = deepClone(datas);
  for (let i in temporaryData) {
    for (let j in temporaryData[i]) {
      let str = j + ',' + temporaryData[i][j];
      if (str.indexOf(searchWord) == -1) {
        delete temporaryData[i][j];
      }
    }
    if (Object.getOwnPropertyNames(temporaryData[i]).length == 0) {
      delete temporaryData[i];
    }
  }
  iconData.value = temporaryData;
};

const currentIcon = ref('');
const setCurrentIconData = (value) => {
  currentIcon.value = value;
};
</script>

<style lang="less">
.icons {
  .icons-search {
    height: 60px;
    margin-bottom: 10px;
    .icons-tip {
      text-align: center;
      font-size: 16px;
      color: #999;
      padding: 3px 0;
      font-size: 12px;
    }
  }
  .icons-body {
    height: calc(100vh - 210px);
    overflow: hidden;
    overflow-y: auto;
    &::-webkit-scrollbar {
      width: 6px;
      height: 10px;
    }
    &::-webkit-scrollbar-track {
      border-radius: 3px;
      background: #f2f2f2;
    }

    &::-webkit-scrollbar-thumb {
      border-radius: 3px;
      background: #dddddd;
    }
    .icon-title {
      span {
        font-size: 16px;
        padding: 0 5px;
        font-weight: 500;
      }
    }
    .icon-item {
      width: 100%;
      text-align: center;
      border-radius: 10px;
      display: inline-block;
      div {
        cursor: pointer;
        padding: 10px 0;
        border-radius: 10px;
        border: 1px solid transparent;
        i {
          font-size: 24px;
        }
        span {
          font-size: 12px;
          transform: scale(0.9);
          display: block;
          padding: 3px;
          color: #888;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
          -webkit-line-clamp: 1;
        }
        &:hover {
          background-color: #ddd;
        }
      }
      .icon-current {
        border: 1px solid rgb(var(--primary-6));
      }
      &:hover {
        background-color: #f2f2f2;
      }
    }
  }
}
</style>
