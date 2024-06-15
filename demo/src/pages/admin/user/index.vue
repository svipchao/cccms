<template>
  <Table
    :fields="table.fields"
    :ignoreFields="table.ignoreFields"
    v-model:form="table.form"
    v-model:columns="table.columns"
    v-model:pagination="table.pagination"
    :data="table.data"
    @reload="getDatas"
  >
    <template #headerButton>
      <a-space>
        <a-button type="primary" @click="editData()">添加</a-button>
      </a-space>
    </template>
    <template #nicknameFilter>
      <a-card :style="{ width: '200px' }">
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
        content="确定要删除吗？"
        type="warning"
        position="left"
        @ok="delData(record)"
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
    :depts="table.depts"
    :roles="table.roles"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive, onMounted, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import { userQuery, userUpdate, userDelete } from '@/api/admin/user.js';
import { useFormEdit } from '@/hooks/form.js';
import Info from './info.vue';

// watch(
//   () => props.dept_id,
//   () => {
//     table.form.dept_id = props.dept_id || 0;
//     getDatas();
//   }
// );

const getDatas = async () => {
  const {
    data: { fields, data, total, dept },
  } = await userQuery({
    ...table.form,
    ...table.pagination,
  });
  table.dept = dept;
  table.data = data;
  table.fields = fields;
  table.pagination.total = total;
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

const delData = (record) => {
  userDelete(record).then((record) => {
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
    user: undefined,
    tag: undefined,
    dept_id: undefined,
  },
  pagination: {
    total: 1,
    page: 1,
    limit: 15,
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
      dataIndex: 'type',
      title: '用户类型',
      width: 145,
      slotName: 'type',
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
      title: '所属组织',
      width: 300,
      ellipsis: true,
      tooltip: true,
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
    // {
    //   dataIndex: 'invite_id',
    //   title: '邀请人',
    //   width: 130,
    //   ellipsis: true,
    //   tooltip: true,
    //   slotName: 'inviteId',
    // },
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
