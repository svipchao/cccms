<template>
  <div class="table-header">
    <div class="table-header-top" v-if="Object.keys(slots).includes('topForm')">
      <a-grid
        :cols="{ xs: 1, sm: 2, md: 3, lg: 4, xl: 5, xxl: 6 }"
        :colGap="10"
        :rowGap="10"
        :collapsed="collapsed"
        :collapsed-rows="2"
      >
        <slot name="topForm">
          <a-grid-item>
            <a-input placeholder="示例内容">
              <template #prefix> 示例 </template>
            </a-input>
          </a-grid-item>
        </slot>
        <a-grid-item suffix class="table-header-button">
          <a-row justify="end">
            <a-space>
              <slot name="topButton">
                <a-button type="primary">提交</a-button>
              </slot>
              <a-button :checked="collapsed" @click="collapsed = !collapsed">
                {{ !collapsed ? "折叠" : "展开" }}
              </a-button>
            </a-space>
          </a-row>
        </a-grid-item>
      </a-grid>
    </div>
    <a-row>
      <a-col flex="auto">
        <slot name="left"> </slot>
      </a-col>
      <a-col flex="auto">
        <a-row justify="end">
          <a-space>
            <slot name="right"></slot>
            <a-button-group>
              <a-button type="text" @click="emits('reload')">
                <template #icon>
                  <i class="iconfont icon-reload"></i>
                </template>
              </a-button>
              <a-select
                v-model:model-value="selectedColumn"
                multiple
                :trigger-props="{ autoFitPopupMinWidth: true, position: 'br' }"
              >
                <template #trigger>
                  <a-button type="text">
                    <template #icon>
                      <i class="iconfont icon-setting"></i>
                    </template>
                  </a-button>
                </template>
                <a-option v-for="column in columns" :value="column.dataIndex" :label="column.title" />
                <template #footer>
                  <a-button @click="changeColumns" type="primary" long>确认</a-button>
                </template>
              </a-select>
            </a-button-group>
          </a-space>
        </a-row>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
import { ref, useSlots } from "vue";
import { tableField } from "@/utils/utils.js";

const slots = useSlots();

// 是否展开top
const collapsed = ref(true);

const emits = defineEmits(["reload", "update:columns"]);

const props = defineProps({
  columns: {},
});

// 二次赋值 防止修改columns
const columns = props.columns;

const getDataIndex = () => {
  const selectedColumns = [];
  for (const i in columns) {
    selectedColumns.push(columns[i].dataIndex);
  }
  return selectedColumns;
};

// 选中的列
const selectedColumn = ref(getDataIndex());

// 更新列
const changeColumns = () => {
  emits("update:columns", tableField(columns, selectedColumn.value));
};
</script>

<style lang="less" scoped>
.table-header {
  width: 100%;
  margin-bottom: 10px;
  .table-header-top {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f2f2f2;
  }
}
.arco-btn-text[type="button"] {
  color: #222;
}
</style>
