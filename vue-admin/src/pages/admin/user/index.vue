<template>
  <a-card>
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getUsers">
      <template #left>
        <a-button type="primary" @click="editUser()" v-permission="'admin/user/create'">
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
      @page-change="handlePageChange"
    >
      <template #groupsFilter>
        <a-card :style="{ width: '200px' }">
          <a-select
            allow-clear
            v-model="tableInfo.form.group_id"
            placeholder="选择父级组织..."
            :fallback-option="false"
          >
            <a-option v-for="group in tableInfo.groups" :value="group.id" :label="group.group_name">
              {{ group.mark }}{{ group.group_name }}
            </a-option>
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getUsers">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #typeFilter>
        <a-card :style="{ width: '200px' }">
          <a-select
            allow-clear
            v-model="tableInfo.form.type"
            placeholder="选择用户类型..."
            :fallback-option="false"
          >
            <a-option v-for="(type, index) in tableInfo.types" :value="index" :label="type" />
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getUsers">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #nicknameFilter>
        <a-card :style="{ width: '200px' }">
          <a-input
            placeholder="模糊查询用户昵称(账号)..."
            allow-clear
            v-model="tableInfo.form.user"
          />
          <template #actions>
            <a-button size="mini" type="primary" @click="getUsers">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #usernameFilter>
        <a-card :style="{ width: '200px' }">
          <a-input
            placeholder="模糊查询用户昵称(账号)..."
            allow-clear
            v-model="tableInfo.form.user"
          />
          <template #actions>
            <a-button size="mini" type="primary" @click="getUsers">确定</a-button>
          </template>
        </a-card>
      </template>
      <template #groups="{ record }">
        <span v-if="record.groups.length > 0">
          <a-tag v-for="group in record.groups">{{ group.group_name }}</a-tag>
        </span>
        <span v-else>未拥有组织</span>
      </template>
      <template #type="{ record }">
        {{ record.type_text }}
      </template>
      <template #token="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          <span>{{ record.token }}</span>
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
          @click="editUser(record)"
          v-permission="'admin/user/update'"
        >
          编辑
        </a-typography-text>
        <a-typography-text
          type="danger"
          @click="delUser(record)"
          v-permission="'admin/user/delete'"
        >
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <user-edit
    v-model:visible="showEdit"
    :data="currentData"
    :types="tableInfo.types"
    :groups="tableInfo.groups"
    @done="getUsers"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { userQuery, userUpdate, userDelete } from "@/api/admin/user";
import TableHeader from "@/components/table/table-header.vue";
import UserEdit from "./user-edit.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getUsers();
});

// 数据
const tableInfo = reactive({
  total: 0, // 数据总条数
  form: {
    type: undefined, // 用户类型
    group_id: undefined, // 组织ID
    user: undefined, // 用户昵称(账号)
    groups: [], // 组织ID集合
    page: 1, // 当前页码
    limit: 10, // 每页数据
  },
  types: [], // 用户类别
  groups: [], // 组织数据
  tableDatas: [], // 表数据 与上方表明数据不一致
  tableColumns: [
    { dataIndex: "id", title: "ID", width: 60 },
    {
      dataIndex: "groups",
      title: "组织",
      width: 200,
      slotName: "groups",
      filterable: {
        slotName: "groupsFilter",
      },
    },
    {
      dataIndex: "type",
      title: "类别",
      width: 100,
      slotName: "type",
      filterable: {
        slotName: "typeFilter",
      },
    },
    {
      dataIndex: "nickname",
      title: "用户昵称",
      width: 200,
      filterable: {
        slotName: "nicknameFilter",
      },
    },
    {
      dataIndex: "username",
      title: "用户账号",
      width: 200,
      filterable: {
        slotName: "usernameFilter",
      },
    },
    { dataIndex: "token", title: "Token", width: 180, slotName: "token" },
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
  userUpdate(record).then((res) => {
    Message.success("更新成功");
  });
};

const handlePageChange = (page) => {
  tableInfo.form.page = page;
  // tableInfo.form.limit = pageSize;
  getUsers();
};

const getUsers = async () => {
  const {
    data: { fields, total, data, groups, types },
  } = await userQuery(tableInfo.form);
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), [
    "groups",
    "operation",
  ]);
  tableInfo.types = types;
  tableInfo.groups = groups;
  tableInfo.total = total;
  tableInfo.tableDatas = data;
};

const editUser = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delUser = (row) => {
  userDelete(row).then((res) => {
    Message.success("删除成功");
    getUsers();
  });
};
</script>
