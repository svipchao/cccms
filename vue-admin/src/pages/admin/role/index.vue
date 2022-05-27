<template>
  <a-card>
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getRoles">
      <template #left>
        <a-button type="primary" @click="editRole()" v-permission="'admin/role/create'">新增</a-button>
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
      <template #role_name="{ record }">
        <span> &nbsp;{{ record.mark }}{{ record.role_name }} </span>
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
        <a-typography-text type="primary" @click="editRole(record)" v-permission="'admin/role/update'">
          编辑
        </a-typography-text>
        <a-typography-text type="danger" @click="delRole(record)" v-permission="'admin/role/delete'">
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <role-edit v-model:visible="showEdit" :data="currentData" :roles="tableInfo.tableDatas" @done="getRoles" />
</template>

<script setup>
import { reactive, ref, nextTick, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { roleQuery, roleUpdate, roleDelete } from "@/api/admin/role";
import TableHeader from "@/components/table/table-header.vue";
import RoleEdit from "./role-edit.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getRoles();
});

// 数据
const tableInfo = reactive({
  tableDatas: [],
  tableColumns: [
    { dataIndex: "role_name", title: "角色名称", width: 150, slotName: "role_name" },
    { dataIndex: "role_desc", title: "角色描述", width: 150 },
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
  roleUpdate(record).then((res) => {
    Message.success("更新成功");
  });
};

const getRoles = async () => {
  const {
    data: { fields, data },
  } = await roleQuery();
  tableInfo.tableDatas = data;
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), ["operation"]);
};

const editRole = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delRole = (row) => {
  roleDelete(row).then((res) => {
    Message.success("删除成功");
    getRoles();
  });
};
</script>
