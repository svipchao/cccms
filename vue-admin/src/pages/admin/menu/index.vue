<template>
  <Table
    :fields="table.fields"
    :ignoreFields="table.ignoreFields"
    default-expand-all-rows
    v-model:columns="table.columns"
    v-model:pagination="table.pagination"
    :data="table.datas"
    row-key="id"
    :draggable="{ type: 'handle', width: 40 }"
    @change="handleChange"
    @reload="getMenus"
  >
    <template #headerButton>
      <a-button type="primary">添加</a-button>
    </template>
    <template #name="{ record }">
      <i :class="['iconfont', record.icon]"></i>
      <span>&nbsp;{{ record.mark }}{{ record.name }}</span>
    </template>
    <template #url="{ record }">
      <a-typography-paragraph
        :ellipsis="{
          rows: 1,
          showTooltip: true,
        }"
      >
        {{ record.url }}
      </a-typography-paragraph>
    </template>
    <template #node="{ record }">
      <a-typography-paragraph
        :ellipsis="{
          rows: 1,
          showTooltip: true,
        }"
      >
        {{ record.node }}
      </a-typography-paragraph>
    </template>
    <template #status="{ record }">
      <a-switch
        v-model:model-value="record.status"
        :checked-value="1"
        :unchecked-value="0"
        @change="changeStatusFun(record)"
      />
    </template>
    <template #operation="{ record }">
      <a-typography-text
        type="primary"
        @click="editMenu(record)"
        v-permission="'admin/menu/update'"
      >
        编辑
      </a-typography-text>
      <a-typography-text
        type="danger"
        @click="delMenu(record)"
        v-permission="'admin/menu/delete'"
      >
        删除
      </a-typography-text>
    </template>
  </Table>
  <menu-edit
    v-model:visible="showEdit"
    :data="currentData"
    :menus="table.datas"
    :type_id="table.form.type_id"
    @done="getMenus"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import {
  menuQuery,
  menuDelete,
  menuUpdate,
  menuUpdateSort,
} from "@/api/admin/menu";
import Types from "@/components/types/index.vue";
import Table from "@/components/table/index.vue";
import MenuEdit from "./menu-edit.vue";

onMounted(() => {
  getMenus();
});

// 数据
const table = reactive({
  form: {
    type_id: 0,
  },
  pagination: {
    total: 1,
    page: 1,
    limit: 15,
  },
  types: [], // 类别
  datas: [], // 表数据
  fields: [],
  ignoreFields: ["operation"],
  columns: [
    { dataIndex: "name", title: "菜单名称", width: 200, slotName: "name" },
    { dataIndex: "url", title: "链接", width: 180, slotName: "url" },
    { dataIndex: "node", title: "权限节点", width: 180, slotName: "node" },
    { dataIndex: "sort", title: "排序", width: 80 },
    { dataIndex: "status", title: "状态", width: 80, slotName: "status" },
    { dataIndex: "create_time", title: "创建时间", width: 180 },
    { dataIndex: "update_time", title: "更新时间", width: 180 },
    {
      dataIndex: "operation",
      title: "操作",
      width: 100,
      fixed: "right",
      slotName: "operation",
    },
  ],
});

// 是否打开弹窗
const showEdit = ref(false);

// 当前编辑的数据
const currentData = ref();

// 切换状态
const changeStatusFun = (record) => {
  menuUpdate(record).then((res) => {
    Message.success("更新成功");
  });
};

const getMenus = async () => {
  const {
    data: { fields, data, types },
  } = await menuQuery({
    ...table.form,
    ...table.pagination,
  });
  table.types = types;
  table.datas = data;
  table.fields = fields;
};

const editMenu = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delMenu = (row) => {
  menuDelete(row).then((res) => {
    Message.success("删除成功");
    getMenus();
  });
};
const handleChange = (data) => {
  let sortData = [];
  let sortIndex = data.length;
  for (let i = 0; i < data.length; i++) {
    data[i]["sort"] = sortIndex--;
    sortData.push({
      id: data[i]["id"],
      sort: data[i]["sort"],
    });
  }
  menuUpdateSort({ data: sortData }).then(() => {
    Message.success("更改排序成功");
  });
  table.datas = data;
};
</script>
