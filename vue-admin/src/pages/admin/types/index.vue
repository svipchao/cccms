<template>
  <a-card>
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getTypes">
      <template #left>
        <a-button type="primary" @click="editTypes()" v-permission="'admin/types/create'">
          新增
        </a-button>
      </template>
    </table-header>
    <a-table
      :columns="tableInfo.tableColumns"
      :data="tableInfo.tableDatas"
      row-key="id"
      :scroll="{
        x: 900,
      }"
      :pagination="{
        total: tableInfo.form.total,
        current: tableInfo.form.page,
        pageSize: tableInfo.form.limit,
      }"
      @page-change="handlePageChange"
    >
      <template #typeFilter>
        <a-card :style="{ width: '200px' }">
          <a-select
            placeholder="选择类别标识..."
            allow-clear
            v-model="tableInfo.form.type"
            :fallback-option="
              () => {
                return {
                  value: undefined,
                  label: '',
                };
              }
            "
          >
            <a-option v-for="(type, index) in tableInfo.type" :key="index" :value="index">
              {{ type }}
            </a-option>
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getTypes">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #type="{ record }">
        {{ record.type_text }}
      </template>
      <template #operation="{ record }">
        <a-typography-text
          type="primary"
          @click="editTypes(record)"
          v-permission="'admin/types/update'"
        >
          编辑
        </a-typography-text>
        <a-typography-text
          type="danger"
          @click="delTypes(record)"
          v-permission="'admin/types/delete'"
        >
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <types-edit v-model:visible="showEdit" :data="currentData" @done="getTypes" />
</template>

<script setup>
import { reactive, ref, nextTick, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { typesQuery, typesDelete } from "@/api/admin/types";
import TableHeader from "@/components/table/table-header.vue";
import TypesEdit from "./types-edit.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getTypes();
});

// 数据
const tableInfo = reactive({
  form: {
    type: 0,
    page: 1,
    limit: 10,
    total: 0,
  },
  type: [],
  tableDatas: [],
  tableColumns: [
    { dataIndex: "id", title: "ID", width: 80 },
    {
      dataIndex: "type",
      title: "类别标识",
      width: 100,
      slotName: "type",
      filterable: {
        slotName: "typeFilter",
      },
    },
    { dataIndex: "name", title: "类别名称", width: 120 },
    { dataIndex: "alias", title: "类别别名", width: 120 },
    { dataIndex: "sort", title: "排序", width: 80 },
    { dataIndex: "create_time", title: "创建时间", width: 180 },
    { dataIndex: "update_time", title: "更新时间", width: 180 },
    { dataIndex: "operation", title: "操作", width: 100, fixed: "right", slotName: "operation" },
  ],
});

// 是否打开弹窗
const showEdit = ref(false);

// 当前编辑的数据
const currentData = ref();

const handlePageChange = (page) => {
  tableInfo.form.page = page;
  // tableInfo.form.limit = pageSize;
  getTypes();
};

const getTypes = async () => {
  const {
    data: { fields, total, data, type },
  } = await typesQuery(tableInfo.form);
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), ["operation"]);
  tableInfo.form.total = total;
  tableInfo.tableDatas = data;
  tableInfo.type = type;
};

const editTypes = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delTypes = (row) => {
  if (tableInfo.tableDatas.length === 1) {
    if (tableInfo.form.page > 1) {
      tableInfo.form.page--;
    } else {
      tableInfo.form.page = 1;
    }
  }
  typesDelete(row).then((res) => {
    Message.success("删除成功");
    getTypes();
  });
};
</script>
