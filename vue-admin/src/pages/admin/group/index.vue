<template>
  <a-card>
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getGroups">
      <template #left>
        <a-button type="primary" @click="editGroup()" v-permission="'admin/group/create'">新增</a-button>
      </template>
    </table-header>
    <a-table
      :columns="tableInfo.tableColumns"
      :data="tableInfo.tableDatas"
      row-key="id"
      :pagination="false"
      :scroll="{
        x: 900,
      }"
    >
      <template #group_name="{ record }">
        <span> &nbsp;{{ record.mark }}{{ record.group_name }} </span>
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
        <a-typography-text type="primary" @click="editGroup(record)" v-permission="'admin/group/update'">
          编辑
        </a-typography-text>
        <a-typography-text type="danger" @click="delGroup(record)" v-permission="'admin/group/delete'">
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <group-edit
    v-model:visible="showEdit"
    :data="currentData"
    :roles="tableInfo.roles"
    :groups="tableInfo.tableDatas"
    @done="getGroups"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { groupQuery, groupUpdate, groupDelete } from "@/api/admin/group";
import TableHeader from "@/components/table/table-header.vue";
import GroupEdit from "./group-edit.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getGroups();
});

// 数据
const tableInfo = reactive({
  roles: [],
  tableDatas: [],
  tableColumns: [
    { dataIndex: "group_name", title: "组织名称", width: 150, slotName: "group_name" },
    { dataIndex: "group_desc", title: "组织描述", width: 150 },
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
  groupUpdate(record).then((res) => {
    Message.success("更新成功");
  });
};

const getGroups = async () => {
  const {
    data: { fields, data, roles },
  } = await groupQuery();
  tableInfo.roles = roles;
  tableInfo.tableDatas = data;
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), ["operation"]);
};

const editGroup = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delGroup = (row) => {
  groupDelete(row).then((res) => {
    Message.success("删除成功");
    getGroups();
  });
};
</script>
