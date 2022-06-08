<template>
  <a-card>
    <types v-model:type_id="tableInfo.form.type_id" :types="tableInfo.types" @reload="getRoutes" />
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getRoutes">
      <template #left>
        <a-space>
          <a-button type="primary" @click="editRoute()" v-permission="'admin/route/create'">
            新增
          </a-button>
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
      :pagination="{
        total: tableInfo.form.total,
        current: tableInfo.form.page,
        pageSize: tableInfo.form.limit,
      }"
      @page-change="handlePageChange"
    >
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
          @click="editRoute(record)"
          v-permission="'admin/route/update'"
        >
          编辑
        </a-typography-text>
        <a-typography-text
          type="danger"
          @click="delRoute(record)"
          v-permission="'admin/route/delete'"
        >
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <route-edit
    v-model:visible="showEdit"
    :data="currentData"
    :type_id="tableInfo.form.type_id"
    @done="getRoutes"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { routeQuery, routeDelete, routeUpdate } from "@/api/admin/route";
import Types from "@/components/types/index.vue";
import TableHeader from "@/components/table/table-header.vue";
import RouteEdit from "./route-edit.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getRoutes();
});

// 数据
const tableInfo = reactive({
  form: {
    type_id: 0,
    page: 1,
    limit: 10,
    total: 0,
  },
  types: [], // 类别
  tableDatas: [], // 表数据
  tableColumns: [
    { dataIndex: "id", title: "ID", width: 50 },
    { dataIndex: "alias", title: "别名", width: 150 },
    { dataIndex: "url", title: "URL", width: 200 },
    { dataIndex: "ext", title: "链接后缀", width: 90 },
    { dataIndex: "name", title: "路由标识", width: 100 },
    { dataIndex: "desc", title: "备注", width: 200, ellipsis: true },
    { dataIndex: "status", title: "状态", width: 70, slotName: "status" },
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
  getRoutes();
};

// 切换状态
const changeStatusFun = (record) => {
  routeUpdate(record).then((res) => {
    Message.success("更新成功");
  });
};

const getRoutes = async () => {
  const {
    data: { fields, data, types, total },
  } = await routeQuery();
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), ["operation"]);
  tableInfo.types = types;
  tableInfo.tableDatas = data;
  tableInfo.form.total = total;
};

const editRoute = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delRoute = (row) => {
  if (tableInfo.tableDatas.length === 1) {
    if (tableInfo.form.page > 1) {
      tableInfo.form.page--;
    } else {
      tableInfo.form.page = 1;
    }
  }
  routeDelete(row).then((res) => {
    Message.success("删除成功");
    getRoutes();
  });
};
</script>
