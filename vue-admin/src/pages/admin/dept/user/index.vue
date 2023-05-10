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
import { deptQuery, deptUpdate, deptDelete } from "@/api/admin/dept.js";
import { useFormEdit } from "@/hooks/form.js";
import { detectDeviceType } from "@/utils/browser.js";

// 这里布局有问题 等官方更新 https://github.com/arco-design/arco-design-vue/issues/2397
onMounted(() => {
  // getDatas();
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
  } = await deptQuery({
    ...table.form,
  });
  table.fields = fields;
  table.datas = data;
};

// 切换状态
const changeStatusFun = (record) => {
  deptUpdate({ id: record.id, status: record.status }).then((res) => {
    Message.success("更新成功");
  });
};

const { showPopup, currentData, updateFormEditStatus } = useFormEdit();

const editData = (row) => {
  updateFormEditStatus(row);
};

const delData = (row) => {
  deptDelete(row).then((res) => {
    Message.success("删除成功");
    getDatas();
  });
};
// 侧栏是否显示
const isShowDept = ref(true);

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
      dataIndex: "dept_name",
      title: "部门名称",
      width: 150,
      ellipsis: true,
      tooltip: true,
      slotName: "deptName",
    },
    {
      dataIndex: "dept_desc",
      title: "部门备注",
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
<style lang="less">
.arco-tree-node-selected .arco-typography {
  color: rgb(var(--primary-6)) !important;
  transition: color 0.2s cubic-bezier(0, 0, 1, 1);
}
.dept-button {
  padding: 6px 0 10px 0;
  border-bottom: 1px solid var(--color-neutral-3);
}
.dept-box {
  display: inline-block;
  height: calc(100vh - 110px);
  padding: 10px;
  background: #fff;
  border: 1px solid var(--color-neutral-3);
  border-right: 0px;
  box-shadow: 0 2px 5px 0 rgb(0 0 0 / 10%);
  @media screen and (max-width: 1200px) {
    width: 300px;
    z-index: 997;
    position: absolute;
    border: 1px solid var(--color-neutral-3);
  }
  .arco-tree-node-title {
    display: block;
  }
  .arco-typography {
    margin-bottom: 0;
  }
  .arco-tree {
    height: calc(100% - 49px);
    overflow: hidden;
    overflow-y: auto;
    &::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }
    &::-webkit-scrollbar-track {
      border-radius: 8px;
    }
    &::-webkit-scrollbar-thumb {
      border-radius: 8px;
      background: var(--color-neutral-3);
    }
  }
  .cccms-mark {
    top: 90px !important;
    z-index: 996 !important;
  }
}
.dept-box-show {
  display: none;
}
</style>
