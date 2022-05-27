<template>
  <a-card>
    <types v-model:type_id="tableInfo.form.type_id" :types="tableInfo.types" @reload="getMenus" />
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getMenus">
      <template #left>
        <a-space>
          <a-button type="primary" @click="editMenu()" v-permission="'admin/menu/create'">新增</a-button>
        </a-space>
      </template>
    </table-header>
    <a-table
      :columns="tableInfo.tableColumns"
      :data="tableInfo.tableDatas"
      row-key="id"
      :scroll="{
        x: 900,
      }"
      :pagination="false"
    >
      <template #name="{ record }">
        <i :class="['iconfont', record.icon]"></i>
        <span> &nbsp;{{ record.mark }}{{ record.name }} </span>
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
        <a-typography-text type="primary" @click="editMenu(record)" v-permission="'admin/menu/update'">
          编辑
        </a-typography-text>
        <a-typography-text type="danger" @click="delMenu(record)" v-permission="'admin/menu/delete'">
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <menu-edit
    v-model:visible="showEdit"
    :data="currentData"
    :menus="tableInfo.tableDatas"
    :type_id="tableInfo.form.type_id"
    @done="getMenus"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { menuQuery, menuDelete, menuUpdate } from "@/api/admin/menu";
import Types from "@/components/types/index.vue";
import TableHeader from "@/components/table/table-header.vue";
import MenuEdit from "./menu-edit.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getMenus();
});

// 数据
const tableInfo = reactive({
  form: {
    type_id: 0,
  },
  types: [], // 类别
  tableDatas: [], // 表数据
  tableColumns: [
    { dataIndex: "id", title: "ID", width: 50 },
    { dataIndex: "name", title: "菜单名称", width: 200, slotName: "name" },
    { dataIndex: "url", title: "链接", width: 180 },
    { dataIndex: "node", title: "权限节点", width: 180 },
    { dataIndex: "sort", title: "排序", width: 80 },
    { dataIndex: "status", title: "状态", width: 80, slotName: "status" },
    { dataIndex: "create_time", title: "创建时间", width: 180 },
    { dataIndex: "update_time", title: "更新时间", width: 180 },
    { dataIndex: "operation", title: "操作", width: 100, fixed: "right", slotName: "operation" },
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
  } = await menuQuery(tableInfo.form);
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), ["operation"]);
  tableInfo.types = types;
  tableInfo.tableDatas = data;
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
</script>
