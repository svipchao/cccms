<template>
  <a-row>
    <a-col flex="300px" :class="isShowDept ? 'dept-box dept-box-show' : 'dept-box'" :wrap="false">
      <div class="dept-button">
        <a-button type="primary" long @click="editData()">添加</a-button>
      </div>
      <a-tree
        v-if="table.datas?.length > 0"
        blockNode
        showLine
        :data="table.datas"
        :fieldNames="{
          key: 'id',
          title: 'dept_name',
          children: 'children',
        }"
        :default-expand-all="false"
      >
        <template #title="node">
          <a-typography-paragraph
            :ellipsis="{
              rows: 1,
              showTooltip: true,
            }"
          >
            {{ node.dept_name }}
          </a-typography-paragraph>
          <a-typography-paragraph
            :ellipsis="{
              rows: 1,
              showTooltip: true,
            }"
            style="font-size: 12px; color: #999"
          >
            {{ node.dept_desc }}
          </a-typography-paragraph>
        </template>
        <template #extra="nodeData">
          <a-button-group>
            <a-button
              type="text"
              size="mini"
              @click="editData(nodeData)"
              v-permission="'admin/dept/update'"
            >
              <template #icon>
                <i class="ri-edit-line"></i>
              </template>
            </a-button>
            <Popconfirm
              content="确定要删除吗？"
              type="warning"
              position="left"
              :ok-loading-time="500"
              @ok="delData(nodeData)"
            >
              <a-button type="text" size="mini" v-permission="'admin/dept/delete'">
                <template #icon>
                  <i class="ri-delete-bin-line" style="color: rgb(var(--danger-6))"></i>
                </template>
              </a-button>
            </Popconfirm>
          </a-button-group>
        </template>
      </a-tree>
      <a-empty v-else />
    </a-col>
    <a-col flex="auto">
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
                <i class="ri-arrow-right-double-line" v-if="isShowDept"></i>
                <i class="ri-arrow-left-double-line" v-else></i>
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
          <Popconfirm
            content="确定要删除吗？"
            type="warning"
            position="left"
            :ok-loading-time="500"
            @ok="delData(record)"
          >
            <a-typography-text type="danger" v-permission="'admin/dept/delete'">
              删除
            </a-typography-text>
          </Popconfirm>
        </template>
      </Table>
      <div class="cccms-mark" @click="isShowDeptFun()" v-show="!isShowDept"></div>
    </a-col>
  </a-row>
  <DataInfo v-model:visible="showData" :data="currentData" :depts="table.datas" @done="getDatas" />
</template>

<script setup>
import Header from "@/components/table/header.vue";
import { ref, reactive, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import Table from "@/components/table/index.vue";
import Popconfirm from "@/components/popconfirm/index.vue";
import DataInfo from "./components/info.vue";
import { deptQuery, deptUpdate, deptDelete } from "@/api/admin/dept.js";
import { useFormEdit } from "@/hooks/form.js";
import { detectDeviceType } from "@/utils/browser.js";

// 这里布局有问题 等官方更新 https://github.com/arco-design/arco-design-vue/issues/2397
onMounted(() => {
  getDatas();
});

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

const { showData, currentData, updateFormEditStatus } = useFormEdit();

const editData = (row) => {
  updateFormEditStatus(row);
};

const delData = (row) => {
  deptDelete(row).then((res) => {
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

const isShowDept = ref(detectDeviceType() == "Desktop");
const isShowDeptFun = () => {
  isShowDept.value = !isShowDept.value;
};
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
  box-shadow: 0 2px 5px 0 rgb(0 0 0 / 8%);
  @media screen and (max-width: 1200px) {
    width: 300px;
    z-index: 997;
    position: absolute;
    border: 1px solid var(--color-neutral-3);
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
}
.dept-box-show {
  display: none;
}
.cccms-mark {
  top: 90px !important;
  z-index: 996 !important;
}
</style>
