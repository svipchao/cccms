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
        <a-button @click="isShowDeptFun">
          <template #icon>
            <i class="ri-arrow-left-double-line" v-if="props.isShowDept"></i>
            <i class="ri-arrow-right-double-line" v-else></i>
          </template>
        </a-button>
        <a-button type="primary" @click="editData()">添加</a-button>
      </a-space>
    </template>
    <template #deptName="{ record }"> {{ record.mark }}{{ record.dept_name }} </template>
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
        v-permission="'admin/dept/update'"
      >
        详情
      </a-typography-text>
      <Popconfirm content="确定要删除吗？" type="warning" position="left" @ok="delData(record)">
        <a-typography-text type="danger" v-permission="'admin/dept/delete'">
          删除
        </a-typography-text>
      </Popconfirm>
    </template>
  </Table>
</template>

<script setup>
import Header from "@/components/table/header.vue";
import { ref, reactive, onMounted, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import Table from "@/components/table/index.vue";
import Mark from "@/components/mark/index.vue";
import Popconfirm from "@/components/popconfirm/index.vue";
import { userQuery, userUpdate, userDelete } from "@/api/admin/user.js";
import { useFormEdit } from "@/hooks/form.js";
import { detectDeviceType } from "@/utils/browser.js";

// 这里布局有问题 等官方更新 https://github.com/arco-design/arco-design-vue/issues/2397
onMounted(() => {
  getDatas();
});

const props = defineProps({
  isShowDept: true,
  dept: undefined,
  currentSelectDeptId: undefined,
});

watch(
  () => props.currentSelectDeptId,
  () => {
    console.log(props.currentSelectDeptId);
  }
);

const emits = defineEmits(["update:isShowDept"]);

const isShowDeptFun = () => {
  emits("update:isShowDept", !props.isShowDept);
};

const getDatas = async () => {
  const {
    data: { fields, data },
  } = await userQuery({
    ...table.form,
  });
  table.fields = fields;
  table.datas = data;
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
  },
  pagination: false,
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
    { dataIndex: "operation", title: "操作", width: 100, fixed: "right", slotName: "operation" },
  ],
});
</script>
