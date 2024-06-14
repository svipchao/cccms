<template>
  <Table
    :fields="table.fields"
    :ignoreFields="table.ignoreFields"
    v-model:form="table.form"
    v-model:columns="table.columns"
    v-model:pagination="table.pagination"
    :data="table.datas"
    @reload="getDatas"
  >
    <template #headerButton>
      <a-button type="primary" @click="editData()">添加</a-button>
    </template>
    <template #roleName="{ record }">
      {{ record.mark }}{{ record.dept_name }}
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
        v-permission="'admin/role/update'"
      >
        <template #icon>
          <i class="ri-edit-line"></i>
        </template>
      </a-button>
      <Popconfirm
        content="确定要删除吗？"
        type="warning"
        position="left"
        @ok="delData(record)"
      >
        <a-button type="text" size="mini" v-permission="'admin/role/delete'">
          <template #icon>
            <i
              class="ri-delete-bin-line"
              style="color: rgb(var(--danger-6))"
            ></i>
          </template>
        </a-button>
      </Popconfirm>
    </template>
  </Table>
  <Info
    v-model:visible="roleEditStatus.data.visible"
    :data="roleEditStatus.data.currentData"
    :roles="table.datas"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import Table from '@/components/table/index.vue';
import { deptQuery, deptUpdate, deptDelete } from '@/api/admin/dept.js';
import { useFormEdit } from '@/hooks/form.js';
import Popconfirm from '@/components/popconfirm/index.vue';
import Info from './info.vue';

onMounted(() => {
  getDatas();
});

const getDatas = async () => {
  const { data } = await deptQuery(table.form);
  table.fields = data.fields;
  table.datas = data.data;
};

// 切换状态
const changeStatusFun = (record) => {
  deptUpdate({ id: record.id, status: record.status }).then((res) => {
    Message.success('更新成功');
  });
};

let roleEditStatus = useFormEdit();

const editData = (row) => {
  roleEditStatus.updateFormEditStatus(row);
};

const delData = (row) => {
  deptDelete(row).then((res) => {
    Message.success('删除成功');
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
  nodes: [],
  fields: [],
  ignoreFields: ['operation'],
  columns: [
    {
      dataIndex: 'dept_name',
      title: '部门名称',
      width: 150,
      ellipsis: true,
      tooltip: true,
      slotName: 'roleName',
    },
    {
      dataIndex: 'dept_desc',
      title: '部门备注',
      width: 200,
      ellipsis: true,
      tooltip: true,
    },
    { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    { dataIndex: 'create_time', title: '创建时间', width: 180, ellipsis: true },
    { dataIndex: 'update_time', title: '更新时间', width: 180, ellipsis: true },
    {
      dataIndex: 'operation',
      title: '操作',
      width: 80,
      fixed: 'right',
      slotName: 'operation',
    },
  ],
});
</script>
