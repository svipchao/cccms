<template>
  <a-card>
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getLogs">
      <template #left>
        <a-popconfirm content="是否清空30天前的日志？" @ok="delLog()" type="warning">
          <a-button type="primary" status="danger" v-permission="'admin/log/delete'">清空30天前日志</a-button>
        </a-popconfirm>
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
        total: tableInfo.total,
        current: tableInfo.form.page,
        pageSize: tableInfo.form.limit,
      }"
      @page-change="handlePageChange"
    >
      <template #userFilter>
        <a-card :style="{ width: '200px' }">
          <a-select
            placeholder="选择用户..."
            allow-clear
            v-model="tableInfo.form.user_id"
            :fallback-option="
              () => {
                return {
                  value: undefined,
                  label: '',
                };
              }
            "
            allow-search
            @search="searchUsers"
            :filter-option="false"
            :show-extra-options="false"
          >
            <a-option v-for="user in tableInfo.users" :value="user.id">
              {{ user.nickname }}({{ user.username }})
            </a-option>
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getLogs">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #user="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          <span>{{ record.nickname }}({{ record.username }})</span>
        </a-typography-paragraph>
      </template>
      <template #reqParam="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          <span>{{ record.req_param }}</span>
        </a-typography-paragraph>
      </template>
      <template #reqUa="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          <span>{{ record.req_ua }}</span>
        </a-typography-paragraph>
      </template>
    </a-table>
  </a-card>
</template>

<script setup>
import { reactive, ref, nextTick, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { logQuery, logDelete } from "@/api/admin/log";
import TableHeader from "@/components/table/table-header.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getLogs();
});

// 数据
const tableInfo = reactive({
  total: 0,
  form: {
    user_id: 0,
    page: 1,
    limit: 10,
  },
  users: [],
  tableDatas: [],
  tableColumns: [
    { dataIndex: "id", title: "ID", width: 80 },
    {
      dataIndex: "user_id",
      title: "用户昵称(账号)",
      width: 160,
      slotName: "user",
      filterable: {
        slotName: "userFilter",
      },
    },
    { dataIndex: "req_method", title: "请求类型", width: 90 },
    { dataIndex: "name", title: "行为名称", width: 180 },
    { dataIndex: "node", title: "操作节点", width: 180 },
    { dataIndex: "req_param", title: "请求参数", width: 230, slotName: "reqParam" },
    { dataIndex: "req_ip", title: "请求IP", width: 130 },
    { dataIndex: "req_ua", title: "User-Agent", width: 230, slotName: "reqUa" },
    { dataIndex: "create_time", title: "创建时间", width: 180 },
  ],
});

// 是否打开弹窗
const showEdit = ref(false);

const handlePageChange = (page) => {
  tableInfo.form.page = page;
  // tableInfo.form.limit = pageSize;
  getLogs();
};

const getLogs = async () => {
  const {
    data: { fields, total, data },
  } = await logQuery(tableInfo.form);
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), ["operation"]);
  tableInfo.total = total;
  tableInfo.tableDatas = data;
};

const delLog = (row) => {
  if (tableInfo.tableDatas.length === 1) {
    if (tableInfo.form.page > 1) {
      tableInfo.form.page--;
    } else {
      tableInfo.form.page = 1;
    }
  }
  logDelete(row).then((res) => {
    Message.success("删除成功");
    getLogs();
  });
};

const searchUsers = async (value) => {
  if (value) {
    const { data } = await logQuery({ like_user_str: value });
    tableInfo.users = data;
  } else {
    tableInfo.users = [];
  }
};
</script>
