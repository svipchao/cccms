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
      column-resizable
      table-layout-fixed
      hide-expand-button-on-empty
      default-expand-all-rows
      :loading="loading"
      :pagination="page"
      :scroll="{ x: 320, y: 'calc(100vh - 172px)' }"
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
} from "vue";
import Header from "./header.vue";
const attrs = useAttrs();
const emits = defineEmits(["reload", "update:pagination"]);

const props = defineProps({
  hideCardBorder: false,
  form: undefined,
  pagination: false,
});
const { proxy } = getCurrentInstance();

onMounted(() => {
  // 关闭分页时
  if (!props.pagination) page = false;
  proxy.$refs.tableRef.expandAll([1, 2, 3, 4, 5, 6, 7, 8, 9]);
  console.log(proxy);
  // tableRef.value.expandAll(true);
});

// 分页配置
let page = reactive({
  total: 0,
  current: 1,
  pageSize: 10,
  showTotal: true,
  showJumper: true,
  showPageSize: true,
  pageSizeOptions: [15, 20, 30, 40, 50, 100],
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
    page.current = 1;
  },
  { deep: true }
);

// 页码改变时触发
const handlePageChange = (current) => {
  props.pagination.page = current;
  props.pagination.current = current;
  emits("update:pagination", props.pagination);
  emits("reload");
};

// 数据条数改变时触发
const handleSizeChange = (pageSize) => {
  props.pagination.limit = pageSize;
  props.pagination.pageSize = pageSize;
  emits("update:pagination", props.pagination);
  emits("reload");
};

const reloadData = () => {
  emits("reload");
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
