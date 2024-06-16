<template>
  <Table
    :fields="table.fields"
    :ignoreFields="table.ignoreFields"
    v-model:recycle="table.form.recycle"
    v-model:form="table.form"
    v-model:columns="table.columns"
    v-model:pagination="table.pagination"
    :data="table.data"
    @reload="getDatas"
  >
    <template #headerButton>
      <a-button
        type="primary"
        v-permission="'admin/dept/create'"
        @click="editData()"
      >
        添加
      </a-button>
    </template>
    <template #deptName="{ record }">
      {{ record.mark }}{{ record.dept_name }}
    </template>
    <template #role="{ record }">
      <div :style="{ width: `280px` }" v-if="record.role.length > 0">
        <a-overflow-list>
          <a-tag v-for="role of record.role" bordered :key="role">
            {{ role.role_name }}
          </a-tag>
        </a-overflow-list>
      </div>
      <div v-else>-</div>
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
        v-permission="'admin/dept/update'"
        v-if="table.form.recycle !== undefined && table.form.recycle == false"
      >
        <template #icon>
          <i class="ri-edit-line"></i>
        </template>
      </a-button>
      <Popconfirm
        v-if="table.form.recycle !== undefined && table.form.recycle == true"
        content="确定要恢复数据吗？"
        type="warning"
        position="left"
        @ok="delData(record, 'restore')"
      >
        <a-button type="text" size="mini" v-permission="'admin/dept/delete'">
          <template #icon>
            <i class="ri-refresh-line" style="color: rgb(var(--warning-6))"></i>
          </template>
        </a-button>
      </Popconfirm>
      <Popconfirm
        content="确定要删除吗？"
        type="warning"
        position="left"
        @ok="
          delData(
            record,
            table.form.recycle !== undefined && table.form.recycle !== false
              ? 'delete'
              : null
          )
        "
      >
        <a-button type="text" size="mini" v-permission="'admin/dept/delete'">
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
    v-model:visible="deptEditStatus.data.visible"
    :data="deptEditStatus.data.currentData"
    :dept="table.data"
    :role="table.role"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive } from 'vue';
import { Message } from '@arco-design/web-vue';
import { deptQuery, deptUpdate, deptDelete } from '@/api/admin/dept.js';
import { useFormEdit } from '@/hooks/form.js';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import Info from './info.vue';

const getDatas = async () => {
  const { data } = await deptQuery(table.form);
  table.fields = data.fields;
  table.data = data.data;
  table.role = data.role;
};

// 切换状态
const changeStatusFun = (record) => {
  deptUpdate({ id: record.id, status: record.status }).then((res) => {
    Message.success('更新成功');
  });
};

let deptEditStatus = useFormEdit();

const editData = (row) => {
  deptEditStatus.updateFormEditStatus(row);
};

const delData = (record, type = null) => {
  deptDelete({ id: record.id, type: type }).then((res) => {
    Message.success('删除成功');
    getDatas();
  });
};

// 数据
const table = reactive({
  form: {
    recycle: false,
  },
  pagination: false,
  data: [],
  role: [],
  fields: [],
  ignoreFields: ['role', 'operation'],
  columns: [
    {
      dataIndex: 'dept_name',
      title: '部门名称',
      width: 200,
      ellipsis: true,
      tooltip: true,
      slotName: 'deptName',
    },
    {
      dataIndex: 'dept_desc',
      title: '部门备注',
      width: 200,
      ellipsis: true,
      tooltip: true,
    },
    {
      dataIndex: 'role',
      title: '拥有角色',
      width: 300,
      ellipsis: true,
      tooltip: true,
      slotName: 'role',
    },
    { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    { dataIndex: 'create_time', title: '创建时间', width: 180, ellipsis: true },
    { dataIndex: 'update_time', title: '更新时间', width: 180, ellipsis: true },
    {
      dataIndex: 'operation',
      title: '操作',
      width: 80,
      align: 'center',
      fixed: 'right',
      slotName: 'operation',
    },
  ],
});
</script>
