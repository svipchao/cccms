<template>
  <Table
    :fields="table.fields"
    :ignoreFields="table.ignoreFields"
    v-model:columns="table.columns"
    v-model:pagination="table.pagination"
    :data="table.datas"
    @reload="getDatas"
  >
    <template #headerButton>
      <a-button type="primary" @click="editData()">添加</a-button>
    </template>
    <template #roleName="{ record }"> {{ record.mark }}{{ record.role_name }} </template>
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
        @click="editData(record)"
        v-permission="'admin/role/update'"
      >
        详情
      </a-typography-text>
      <Popconfirm content="确定要删除吗？" type="warning" position="left" @ok="delData(record)">
        <a-typography-text type="danger" v-permission="'admin/role/delete'">
          删除
        </a-typography-text>
      </Popconfirm>
    </template>
  </Table>
  <DataInfo v-model:visible="showData" :data="currentData" :roles="table.datas" @done="getDatas" />
</template>

<script setup>
import { ref, reactive, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import Table from "@/components/table/index.vue";
import Popconfirm from "@/components/popconfirm/index.vue";
import DataInfo from "./components/info.vue";
import { roleQuery, roleUpdate, roleDelete } from "@/api/admin/role.js";
import { useFormEdit } from "@/hooks/form.js";

onMounted(() => {
  getDatas();
});

const getDatas = async () => {
  const {
    data: { fields, data },
  } = await roleQuery({
    ...table.form,
  });
  table.fields = fields;
  table.datas = data;
};

// 切换状态
const changeStatusFun = (record) => {
  roleUpdate({ id: record.id, status: record.status }).then((res) => {
    Message.success("更新成功");
  });
};

const { showPopup, currentData, updateFormEditStatus } = useFormEdit();

const editData = (row) => {
  updateFormEditStatus(row);
};

const delData = (row) => {
  roleDelete(row).then((res) => {
    Message.success("删除成功");
    getDatas();
  });
};

// 数据
const table = reactive({
  form: {
    user: undefined,
  },
  pagination: false,
  datas: [],
  fields: [],
  ignoreFields: ["operation"],
  columns: [
    {
      dataIndex: "role_name",
      title: "角色名称",
      width: 150,
      ellipsis: true,
      tooltip: true,
      slotName: "roleName",
    },
    {
      dataIndex: "role_desc",
      title: "角色备注",
      width: 200,
      ellipsis: true,
      tooltip: true,
    },
    { dataIndex: "status", title: "状态", width: 80, slotName: "status" },
    { dataIndex: "create_time", title: "创建时间", width: 180, ellipsis: true },
    { dataIndex: "update_time", title: "更新时间", width: 180, ellipsis: true },
    { dataIndex: "operation", title: "操作", width: 95, fixed: "right", slotName: "operation" },
  ],
});
</script>
