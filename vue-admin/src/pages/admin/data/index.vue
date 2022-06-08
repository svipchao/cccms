<template>
  <a-card>
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getDatas">
      <template #left>
        <a-button type="primary" @click="editData()" v-permission="'admin/data/create'">
          新增
        </a-button>
        <a-typography-text type="warning">
          注意：如果条件与值皆为空 则 该角色没有该表字段权限
        </a-typography-text>
      </template>
    </table-header>
    <a-table
      :columns="tableInfo.tableColumns"
      :data="tableInfo.tableDatas"
      row-key="id"
      :scroll="{
        x: 900,
      }"
      @page-change="handlePageChange"
    >
      <template #roleNameFilter>
        <a-card hoverable :style="{ width: '200px', marginBottom: '20px' }">
          <a-select
            allow-clear
            v-model="tableInfo.form.role_id"
            placeholder="选择父级角色..."
            :fallback-option="false"
          >
            <a-option v-for="role in tableInfo.roles" :value="role.id" :label="role.role_name">
              {{ role.mark }}{{ role.role_name }}
            </a-option>
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getDatas()">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #tableFilter>
        <a-card hoverable :style="{ width: '200px', marginBottom: '20px' }">
          <a-select allow-clear v-model="tableInfo.form.table" placeholder="选择数据表...">
            <a-option
              v-for="table in tableInfo.table"
              :value="table.table"
              :label="table.table_name + '(' + table.table + ')'"
            />
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getDatas()">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #table="{ record }"> {{ record.table }}({{ record.table_name }}) </template>
      <template #role="{ record }"> {{ record.role_name }}({{ record.role_id }}) </template>
      <template #field="{ record }"> {{ record.field }}({{ record.field_name }}) </template>
      <template #where="{ record }">
        {{ record.where == 0 ? "条件为空" : record.where }}
      </template>
      <template #value="{ record }">
        {{ record.value == 0 ? "值为空" : record.value }}
      </template>
      <template #operation="{ record }">
        <a-typography-text
          type="primary"
          @click="editData(record)"
          v-permission="'admin/data/update'"
        >
          编辑
        </a-typography-text>
        <a-typography-text
          type="danger"
          @click="delData(record)"
          v-permission="'admin/data/delete'"
        >
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <data-edit
    v-model:visible="showEdit"
    :data="currentData"
    :table="tableInfo.table"
    :roles="tableInfo.roles"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { dataQuery, dataDelete } from "@/api/admin/data";
import TableHeader from "@/components/table/table-header.vue";
import DataEdit from "./data-edit.vue";

onMounted(() => {
  getDatas();
});

// 数据
const tableInfo = reactive({
  total: 0, // 数据总条数
  form: {
    role_id: undefined, // 角色ID
    table: undefined, // 数据表
    page: 1, // 当前页码
    limit: 10, // 每页数据
  },
  table: [], // 表名数据
  roles: [], // 角色数据
  tableDatas: [], // 表数据 与上方表明数据不一致
  tableColumns: [
    {
      dataIndex: "role_name",
      title: "角色名称(ID)",
      width: 200,
      slotName: "role",
      filterable: {
        slotName: "roleNameFilter",
      },
    },
    {
      dataIndex: "table",
      title: "数据表(名称)",
      width: 200,
      slotName: "table",
      filterable: {
        slotName: "tableFilter",
      },
    },
    { dataIndex: "field", title: "字段(名称)", width: 200, slotName: "field" },
    { dataIndex: "where", title: "条件", width: 180, slotName: "where" },
    { dataIndex: "value", title: "值", width: 180, slotName: "value" },
    { dataIndex: "operation", title: "操作", width: 100, fixed: "right", slotName: "operation" },
  ],
});

// 是否打开弹窗
const showEdit = ref(false);

// 当前编辑的数据
const currentData = ref();

const handlePageChange = (page) => {
  tableInfo.form.page = page;
  getDatas();
};

const getDatas = async () => {
  const {
    data: { total, data, roles, table },
  } = await dataQuery(tableInfo.form);
  tableInfo.table = table;
  tableInfo.roles = roles;
  tableInfo.total = total;
  tableInfo.tableDatas = data;
};

const editData = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delData = (row) => {
  dataDelete(row).then((res) => {
    Message.success("删除成功");
    getDatas();
  });
};
</script>
