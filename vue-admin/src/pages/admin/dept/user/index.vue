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
      <a-space>
        <a-button type="primary" @click="editData()">添加</a-button>
      </a-space>
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
      <a-button
        type="text"
        size="mini"
        @click="editData(record)"
        v-permission="'admin/user/update'"
      >
        <template #icon>
          <i class="ri-edit-line"></i>
        </template>
      </a-button>
      <Popconfirm content="确定要删除吗？" type="warning" position="left" @ok="delData(record)">
        <a-button type="text" size="mini" v-permission="'admin/user/delete'">
          <template #icon>
            <i class="ri-delete-bin-line" style="color: rgb(var(--danger-6))"></i>
          </template>
        </a-button>
      </Popconfirm>
    </template>
  </Table>
</template>

<script setup>
import Header from "@/components/table/header.vue";
import { ref, reactive, onMounted, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import Table from "@/components/table/index.vue";
import Popconfirm from "@/components/popconfirm/index.vue";
import { userQuery, userUpdate, userDelete } from "@/api/admin/user.js";
import { useFormEdit } from "@/hooks/form.js";
import { detectDeviceType } from "@/utils/browser.js";

onMounted(() => {
  getDatas();
});

const props = defineProps({
  dept: undefined,
  currentSelectDeptId: undefined,
});

watch(
  () => props.currentSelectDeptId,
  () => {
    table.form.dept_id = props.currentSelectDeptId;
    getDatas();
  }
);

const getDatas = async () => {
  const {
    data: { fields, data, total },
  } = await userQuery({
    ...table.form,
    ...table.pagination,
  });
  table.datas = data;
  table.fields = fields;
  console.log(123);
  table.pagination.total = total;
};

const demo1 = () => {
  table.pagination.total = 20;
};

// 切换状态
const changeStatusFun = (record) => {
  userUpdate({ id: record.id, status: record.status }).then((res) => {
    Message.success("更新成功");
  });
};

const { showPopup, currentData, updateFormEditStatus } = useFormEdit();

const editData = (row) => {
  updateFormEditStatus(row);
};

const delData = (row) => {
  userDelete(row).then((res) => {
    Message.success("删除成功");
    getDatas();
  });
};

// 数据
const table = reactive({
  form: {
    user: undefined,
    dept_id: undefined,
  },
  pagination: {
    total: 1,
    page: 1,
    limit: 15,
  },
  datas: [],
  fields: [],
  ignoreFields: ["operation"],
  columns: [
    { dataIndex: "id", title: "ID", width: 60 },
    {
      dataIndex: "nickname",
      title: "用户昵称",
      width: 130,
      ellipsis: true,
      tooltip: true,
      filterable: {
        slotName: "nicknameFilter",
      },
    },
    {
      dataIndex: "username",
      title: "用户账号",
      width: 130,
      ellipsis: true,
      tooltip: true,
      filterable: {
        slotName: "usernameFilter",
      },
    },
    {
      dataIndex: "user_id",
      title: "邀请人",
      width: 130,
      ellipsis: true,
      tooltip: true,
      slotName: "userId",
    },
    { dataIndex: "status", title: "状态", width: 80, slotName: "status" },
    { dataIndex: "create_time", title: "创建时间", width: 180, ellipsis: true, tooltip: true },
    { dataIndex: "update_time", title: "更新时间", width: 180, ellipsis: true, tooltip: true },
    {
      dataIndex: "operation",
      title: "操作",
      width: 80,
      align: "center",
      fixed: "right",
      slotName: "operation",
    },
  ],
});
</script>
