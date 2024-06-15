<template>
  <div class="header">
    <div
      class="form"
      v-if="
        Object.keys(slots).includes('headerForm') ||
        Object.keys(slots).includes('headerFormButton')
      "
    >
      <a-grid
        :cols="{ xs: 1, sm: 2, md: 3, lg: 4, xl: 6, xxl: 8 }"
        :colGap="10"
        :rowGap="10"
        :collapsed="headerFormStatus"
        :collapsed-rows="3"
      >
        <slot name="headerForm">
          <a-grid-item>
            <a-input placeholder="示例内容">
              <template #prefix>示例</template>
            </a-input>
          </a-grid-item>
        </slot>
        <a-grid-item suffix class="header-button">
          <a-row justify="end">
            <a-space>
              <slot name="headerFormButton">
                <a-button type="primary">提交</a-button>
              </slot>
              <a-button
                :checked="headerFormStatus"
                @click="headerFormStatus = !headerFormStatus"
              >
                {{ !headerFormStatus ? '折叠' : '展开' }}
              </a-button>
            </a-space>
          </a-row>
        </a-grid-item>
      </a-grid>
    </div>
    <div class="button">
      <a-row
        v-if="
          Object.keys(slots).includes('headerButton') ||
          Object.keys(slots).includes('headerExtend')
        "
      >
        <a-col flex="auto">
          <slot name="headerButton"></slot>
        </a-col>
        <a-col flex="auto">
          <a-row justify="end">
            <a-space>
              <slot name="headerExtend"></slot>
              <a-button-group>
                <a-tooltip content="回收站" v-if="props.recycle !== undefined">
                  <a-button type="text" @click="openRecycle()">
                    <template #icon>
                      <i
                        class="ri-recycle-line"
                        style="color: rgb(var(--primary-6))"
                        v-if="props.recycle"
                      ></i>
                      <i class="ri-recycle-line" v-else></i>
                    </template>
                  </a-button>
                </a-tooltip>
                <a-tooltip content="刷新">
                  <a-button type="text" @click="emits('reload')">
                    <template #icon>
                      <i class="ri-refresh-line"></i>
                    </template>
                  </a-button>
                </a-tooltip>
                <a-select
                  multiple
                  v-model:model-value="selectedColumn"
                  :scrollbar="{
                    type: 'track',
                  }"
                  :trigger-props="{
                    clickToClose: true,
                    autoFitPopupMinWidth: true,
                    position: 'br',
                  }"
                >
                  <template #trigger>
                    <a-button type="text">
                      <template #icon>
                        <i class="ri-list-settings-line"></i>
                      </template>
                    </a-button>
                  </template>
                  <a-option
                    v-for="column in columns"
                    :value="column.dataIndex"
                    :label="column.title"
                  />
                </a-select>
              </a-button-group>
            </a-space>
          </a-row>
        </a-col>
      </a-row>
    </div>
  </div>
</template>

<script setup>
import { ref, useSlots, watch } from 'vue';

const slots = useSlots();

// 头部表单收缩状态
const headerFormStatus = ref(true);

const emits = defineEmits(['reload', 'update:columns', 'update:recycle']);

/**
 * 思路：
 * 1、由于fields是异步获取 所以需要watch监听
 * 2、获取列与选中的列(默认全选)
 * 3、异步获取列时需要使用props.columns去除无权限的列
 *    这里将处理过的列存起来 后面要用 这里不会更新父级组件的列
 * 4、监听select选中状态 使用columns更新父级组件的列
 * PS：之所以使用ignoreFields来忽略列 而不在columns内定义忽略 是因为父级组件有可能会一个列存在多个列值
 *     实际上业务一个单元格有可能会出现多个字段 父级组件可以方便处理显示隐藏问题
 */
const props = defineProps({
  recycle: false,
  columns: {},
  fields: {},
  ignoreFields: {},
});

// 列
const columns = ref();

// 选中列
const selectedColumn = ref();

watch(
  () => props.fields,
  () => {
    const { columnsArr, selectedArr } = tableField(
      props.columns,
      Object.keys(props.fields),
      props.ignoreFields
    );
    columns.value = columnsArr;
    selectedColumn.value = selectedArr;
  }
);

watch(selectedColumn, () => {
  const { columnsArr } = tableField(columns.value, selectedColumn.value);
  emits('update:columns', columnsArr);
});

// 回收站
const openRecycle = () => {
  emits('update:recycle', !props.recycle);
  emits('reload');
};

/**
 * 表格字段处理
 * @param {*} columns 表格列表信息
 * @param {*} fields 字段信息
 * @param {*} ignoreField 忽略的字段
 */
const tableField = (columns, fields = [], ignoreFields = []) => {
  const columnsArr = [];
  const selectedArr = [];
  columns.forEach(function (item) {
    if (
      item.dataIndex === undefined ||
      fields.indexOf(item.dataIndex) !== -1 ||
      ignoreFields.indexOf(item.dataIndex) !== -1
    ) {
      columnsArr.push(item);
      selectedArr.push(item.dataIndex);
    }
  });
  return { columnsArr, selectedArr };
};
</script>

<style lang="less" scoped>
.header {
  margin-bottom: 10px;
}
.form {
  width: 100%;
  margin-bottom: 10px;
  padding-bottom: 10px;
  border-bottom: 1px solid #f2f2f2;
}
.arco-btn-text[type='button'] {
  color: #222;
}
</style>
