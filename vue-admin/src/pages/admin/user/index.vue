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
      <a-space>
        <a-button
          type="primary"
          v-permission="'admin/user/create'"
          @click="editData()"
        >
          添加
        </a-button>
      </a-space>
    </template>
    <template #nicknameFilter>
      <a-card :style="{ width: '300px' }">
        <a-input
          placeholder="模糊查询用户姓名(账号)..."
          allow-clear
          v-model="table.form.user"
        />
        <template #actions>
          <a-button size="mini" type="primary" @click="getDatas">确定</a-button>
        </template>
      </a-card>
    </template>
    <template #deptFilter>
      <a-card :style="{ width: '300px' }">
        <a-select
          allow-clear
          v-model="table.form.dept_id"
          placeholder="选择部门..."
          :fallback-option="false"
        >
          <template #prefix>部门</template>
          <a-option
            v-for="dept in table.dept"
            :value="dept.id"
            :label="dept.dept_name"
          >
            {{ dept.mark }}{{ dept.dept_name }}
          </a-option>
        </a-select>
        <template #actions>
          <a-button size="mini" type="primary" @click="getDatas">确定</a-button>
        </template>
      </a-card>
    </template>
    <template #id="{ record }">
      {{ record.id + 100000 }}
    </template>
    <template #nickname="{ record }">
      <a-typography-paragraph
        :ellipsis="{
          rows: 1,
          showTooltip: true,
        }"
      >
        <span>{{ record.nickname || '-' }}({{ record.username || '-' }})</span>
      </a-typography-paragraph>
    </template>
    <template #tags="{ record }">
      <a-space wrap>
        <a-tag
          v-for="(tag, index) of record.tags"
          :key="tag"
          closable
          :style="
            tag == table.form.tag
              ? {
                  color: '#168cff',
                  cursor: 'pointer',
                  border: '1px solid #168cff',
                }
              : {}
          "
          @click="setTag(tag)"
          @close="delTag(record, tag)"
        >
          {{ tag }}
        </a-tag>
        <a-input
          v-if="record.showAddTag"
          v-focus
          size="mini"
          :style="{ width: '100px' }"
          v-model.trim="record.tag"
          @keyup.enter="addTag(record)"
          @blur="addTag(record)"
        />
        <a-tag
          v-else
          :style="{
            color: '#666',
            cursor: 'pointer',
          }"
          @click="editTag(record)"
        >
          添加
        </a-tag>
      </a-space>
    </template>
    <template #dept="{ record }">
      <div :style="{ width: `280px` }" v-if="record.dept.length > 0">
        <a-overflow-list>
          <a-tag
            v-for="dept of record.dept"
            bordered
            :key="dept"
            :color="dept.id === table.form.dept_id ? 'blue' : ''"
            @click="searchDeptUserFun(dept.id)"
          >
            {{ dept.dept_name }}
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
    <template #phone="{ record }">
      <a-typography-paragraph
        :ellipsis="{
          rows: 1,
          showTooltip: true,
        }"
      >
        <span>{{ record.phone || '-' }}</span>
      </a-typography-paragraph>
    </template>
    <template #operation="{ record }">
      <a-button
        v-if="table.form.recycle !== undefined && table.form.recycle == false"
        type="text"
        size="mini"
        @click="editData(record)"
        v-permission="'admin/user/update'"
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
        <a-button type="text" size="mini" v-permission="'admin/user/delete'">
          <template #icon>
            <i class="ri-refresh-line" style="color: rgb(var(--warning-6))"></i>
          </template>
        </a-button>
      </Popconfirm>
      <Popconfirm
        content="确定要删除数据吗？"
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
        <a-button type="text" size="mini" v-permission="'admin/user/delete'">
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
    v-model:visible="userEditStatus.data.visible"
    :data="userEditStatus.data.currentData"
    :dept="table.dept"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive } from 'vue';
import { Message } from '@arco-design/web-vue';
import { userQuery, userUpdate, userDelete } from '@/api/admin/user.js';
import { useFormEdit } from '@/hooks/form.js';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import Info from './info.vue';

const getDatas = async () => {
  const { data } = await userQuery({
    ...table.form,
    ...table.pagination,
  });
  table.dept = data.dept;
  table.data = data.data;
  table.fields = data.fields;
  table.pagination.total = data.total;
};

const searchDeptUserFun = (id) => {
  if (table.form.dept_id == id) {
    table.form.dept_id = 0;
  } else {
    table.form.dept_id = id;
  }
  getDatas();
};

// 切换状态
const changeStatusFun = (record) => {
  userUpdate({ id: record.id, status: record.status }).then((res) => {
    Message.success('更新成功');
  });
};

let userEditStatus = useFormEdit();

const editData = (record) => {
  userEditStatus.updateFormEditStatus(record);
};

const delData = (record, type = null) => {
  userDelete({ id: record.id, type: type }).then((record) => {
    Message.success('删除成功');
    getDatas();
  });
};

// 标签处理
const delTag = (record, tag) => {
  record.tags.forEach(function (item, index) {
    if (item == tag) {
      record.tags.splice(index, 1);
    }
  });
  userUpdate({ id: record.id, tags: record.tags }).then((res) => {
    Message.success('删除成功');
  });
};

const addTag = (record) => {
  record.tags.push(record.tag);
  record.showAddTag = false;
  userUpdate({ id: record.id, tags: record.tags }).then((res) => {
    Message.success('添加成功');
  });
};

const editTag = (record) => {
  record.tag = '';
  record.showAddTag = true;
};

const setTag = (tag) => {
  if (tag == table.form.tag) {
    table.form.tag = undefined;
  } else {
    table.form.tag = tag;
  }
  getDatas();
};

// 数据
const table = reactive({
  form: {
    recycle: false,
    user: undefined,
    tag: undefined,
    dept_id: undefined,
  },
  pagination: {
    page: 1,
    limit: 15,
    total: 0,
  },
  dept: [],
  data: [],
  fields: [],
  ignoreFields: ['dept', 'operation'],
  columns: [
    {
      dataIndex: 'id',
      title: 'ID',
      width: 100,
      slotName: 'id',
    },
    {
      dataIndex: 'nickname',
      title: '账号信息',
      width: 200,
      ellipsis: true,
      tooltip: true,
      filterable: {
        slotName: 'nicknameFilter',
      },
      slotName: 'nickname',
    },
    {
      dataIndex: 'tags',
      title: '用户标签',
      width: 300,
      ellipsis: true,
      tooltip: true,
      slotName: 'tags',
    },
    {
      dataIndex: 'dept',
      title: '所属部门',
      width: 300,
      ellipsis: true,
      tooltip: true,
      filterable: {
        slotName: 'deptFilter',
      },
      slotName: 'dept',
    },
    {
      dataIndex: 'phone',
      title: '手机号码',
      width: 150,
      ellipsis: true,
      tooltip: true,
      slotName: 'phone',
    },
    { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    {
      dataIndex: 'create_time',
      title: '创建时间',
      width: 180,
      ellipsis: true,
      tooltip: true,
    },
    {
      dataIndex: 'update_time',
      title: '更新时间',
      width: 180,
      ellipsis: true,
      tooltip: true,
    },
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
<style scoped lang="less">
.arco-tag {
  cursor: pointer;
}
:deep(.arco-space-item) {
  margin: 2px 4px 2px 0 !important;
}
</style>
