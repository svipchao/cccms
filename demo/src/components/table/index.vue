<template>
  <a-card
    :bordered="false"
    :class="[props.hideCardBorder !== undefined ? 'hide-card-border' : '']"
  >
    <Header v-bind="$attrs" @reload="reloadData">
      <template v-for="(_, name) in $slots" v-slot:[name]="data">
        <slot :name="name" v-bind="data" />
      </template>
    </Header>
    <a-table
      ref="tableRef"
      stripe
      bordered
      hoverable
      sticky-header
      :column-resizable="false"
      :table-layout-fixed="false"
      hide-expand-button-on-empty
      default-expand-all-rows
      :loading="loading"
      :pagination="page"
      :scroll="{ minWidth: 320, maxHeight: 'calc(100vh - 225px)' }"
      @page-change="handlePageChange"
      @page-size-change="handleSizeChange"
      v-bind="$attrs"
    >
      <template v-for="(_, name) in $slots" v-slot:[name]="data">
        <slot :name="name" v-bind="data" />
      </template>
    </a-table>
  </a-card>
</template>

<script setup>
import {
  reactive,
  watch,
  useAttrs,
  onMounted,
  ref,
  nextTick,
  getCurrentInstance,
} from 'vue';
import Header from './header.vue';
import { deepClone } from '@/utils/utils.js';
import { debounce } from 'lodash';

const attrs = useAttrs();
const emits = defineEmits(['reload', 'update:pagination']);

const props = defineProps({
  hideCardBorder: false,
  form: undefined,
  pagination: false,
});

const { proxy } = getCurrentInstance();

onMounted(() => {
  // 关闭分页时
  if (!props.pagination) page = false;
  reloadData();
});

// 分页配置
let page = reactive({
  total: 0,
  current: 1,
  pageSize: 10,
  showTotal: true,
  showJumper: true,
  showPageSize: true,
  pageSizeOptions: [10, 15, 20, 30, 40, 50, 100],
});

const loading = ref(true);
watch(
  () => attrs.data,
  () => {
    loading.value = false;
  }
);

watch(
  () => props.pagination,
  () => {
    if (props.pagination !== false) {
      page = Object.assign({ ...page, ...props.pagination });
      page.current = page.page;
      page.pageSize = page.limit;
      if (props.pagination?.simple === undefined && window.innerWidth < 930) {
        // 自动简洁分页
        page.simple = true;
        page.showTotal = false;
        page.showJumper = false;
        page.showPageSize = false;
      }
    }
  },
  { deep: true }
);

watch(
  () => props.form,
  () => {
    let pagination = deepClone(props.pagination);
    pagination.page = 1;
    // pagination.current = 1;
    emits('update:pagination', pagination);
    // reloadData();
  },
  { deep: true }
);
// watch(
//   () => props.form,
//   debounce(() => {
//     let pagination = deepClone(props.pagination);
//     pagination.page = 1;
//     // pagination.current = 1;
//     emits('update:pagination', pagination);
//     // reloadData();
//   }, 10),
//   { deep: true }
// );

// 页码改变时触发
const handlePageChange = (current) => {
  let pagination = deepClone(props.pagination);
  pagination.page = current;
  // pagination.current = current;
  emits('update:pagination', pagination);
  reloadData();
};

// 数据条数改变时触发
const handleSizeChange = (pageSize) => {
  let pagination = deepClone(props.pagination);
  pagination.limit = pageSize;
  // pagination.pageSize = current;
  emits('update:pagination', pagination);
  reloadData();
};

const reloadData = () => {
  emits('reload');
};
</script>

<style lang="less">
.hide-card-border {
  border: 0px;
  .arco-card-body {
    padding: 0px;
  }
}
:deep(.arco-table .arco-table-element) {
  width: auto;
  &::-webkit-scrollbar {
    width: 8px;
    height: 8px;
  }
  &::-webkit-scrollbar-track {
    border-radius: 8px;
  }
  &::-webkit-scrollbar-thumb {
    border-radius: 8px;
    background: var(--color-neutral-3);
  }
}
</style>
