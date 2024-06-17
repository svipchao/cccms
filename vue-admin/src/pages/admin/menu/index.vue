<template>
  <a-card>
    <a-tabs
      :editable="false"
      hide-content
      show-add-button
      :active-key="table.form.parent_id"
      @change="switchMenu"
      style="padding-bottom: 10px"
    >
      <a-tab-pane
        v-for="(cate, index) in table.cate"
        :key="cate.id"
        :title="cate.name"
      />
    </a-tabs>
    <Table
      hideCardBorder
      :fields="table.fields"
      :ignoreFields="table.ignoreFields"
      v-model:recycle="table.form.recycle"
      v-model:form="table.form"
      v-model:columns="table.columns"
      v-model:pagination="table.pagination"
      :data="table.data"
      row-key="id"
      :draggable="{ type: 'handle', width: 40 }"
      @change="handleChange"
      @reload="getDatas"
    >
      <template #headerButton>
        <a-space>
          <a-button
            type="primary"
            @click="editMenu()"
            v-permission="'admin/menu/create'"
          >
            新增
          </a-button>
        </a-space>
      </template>
      <template #name="{ record }">
        <a-link
          v-if="isLink(record.url)"
          :href="record.url"
          target="_blank"
          :hoverable="false"
        >
          <i :class="record.icon"></i>
          <span>&nbsp;{{ record.mark }}{{ record.name }}</span>
        </a-link>
        <span v-else>
          <i :class="record.icon"></i>
          <span>&nbsp;{{ record.mark }}{{ record.name }}</span>
        </span>
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
          @click="editMenu(record)"
          v-permission="'admin/menu/update'"
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
          <a-button type="text" size="mini" v-permission="'admin/menu/delete'">
            <template #icon>
              <i
                class="ri-refresh-line"
                style="color: rgb(var(--warning-6))"
              ></i>
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
          <a-button type="text" size="mini" v-permission="'admin/menu/delete'">
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
  </a-card>
  <Info
    v-model:visible="menuEditStatus.data.visible"
    :data="menuEditStatus.data.currentData"
    :menus="table.data"
    :parent_id="table.form.parent_id"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive } from 'vue';
import { Message } from '@arco-design/web-vue';
import { menuQuery, menuDelete, menuUpdate } from '@/api/admin/menu.js';
import { useUserStore } from '@/stores/admin/user.js';
import { useFormEdit } from '@/hooks/form.js';
import { isLink } from '@/utils/utils.js';
import Popconfirm from '@/components/popconfirm/index.vue';
import Table from '@/components/table/index.vue';
import Info from './info.vue';

let menuEditStatus = useFormEdit();

const editMenu = (record) => {
  menuEditStatus.updateFormEditStatus(record);
};

// 切换状态
const changeStatusFun = (record) => {
  menuUpdate(record).then((res) => {
    Message.success('更新成功');
    useUserStore().setAccessToken();
  });
};

const getDatas = async () => {
  const { data } = await menuQuery(table.form);
  table.fields = data.fields;
  table.data = data.data;
  table.cate = data.cate;
};

const delData = (record, type = null) => {
  menuDelete({ id: record.id, type: type }).then((res) => {
    Message.success('删除成功');
    useUserStore().setAccessToken();
    getDatas();
  });
};

const handleChange = (data) => {
  let sortData = [];
  let sortIndex = data.length;
  for (let i = 0; i < data.length; i++) {
    data[i]['sort'] = sortIndex--;
    sortData.push({
      id: data[i]['id'],
      sort: data[i]['sort'],
    });
  }
  menuUpdate({ type: 'sort', data: sortData }).then(() => {
    Message.success('更改排序成功');
    useUserStore().setAccessToken();
    getDatas();
  });
  table.data = data;
};

const switchMenu = (key) => {
  table.form.parent_id = key;
  getDatas();
};

const table = reactive({
  form: {
    recycle: false,
    parent_id: 1,
  },
  pagination: false,
  cate: [],
  data: [],
  fields: [],
  ignoreFields: ['operation'],
  columns: [
    {
      dataIndex: 'name',
      title: '菜单名称',
      width: 200,
      slotName: 'name',
      ellipsis: true,
      tooltip: true,
    },
    {
      dataIndex: 'node',
      title: '权限节点',
      width: 200,
      slotName: 'node',
      ellipsis: true,
      tooltip: true,
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
      fixed: 'right',
      slotName: 'operation',
    },
  ],
});
</script>

<style scoped>
.arco-typography {
  margin-bottom: 0;
}
</style>
