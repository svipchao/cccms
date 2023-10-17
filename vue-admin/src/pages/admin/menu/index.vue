<template>
  <a-card>
    <types
      v-model:type_id="table.form.parent_id"
      :types="table.cates"
      @reload="getDatas"
    />
    <Table
      hideCardBorder
      :fields="table.fields"
      :ignoreFields="table.ignoreFields"
      v-model:columns="table.columns"
      v-model:pagination="table.pagination"
      :data="table.datas"
      row-key="id"
      :draggable="{ type: 'handle', width: 40 }"
      @change="handleChange"
      @reload="getDatas"
    >
      <template #headerButton>
        <a-button
          type="primary"
          @click="editMenu()"
          v-permission="'admin/menu/create'"
        >
          新增
        </a-button>
      </template>
      <template #name="{ record }">
        <i :class="record.icon"></i>
        <span>&nbsp;{{ record.mark }}{{ record.name }}</span>
      </template>
      <template #url="{ record }">
        <a-tooltip :content="record.url">
          <a-link :href="record.url" target="_blank" :hoverable="false">
            打开
          </a-link>
        </a-tooltip>
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
        >
          <template #icon>
            <i class="ri-edit-line"></i>
          </template>
        </a-button>
        <Popconfirm
          content="确定要删除吗？"
          type="warning"
          position="left"
          @click="delMenu(record)"
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
  <menu-edit
    v-model:visible="showEdit"
    :data="currentData"
    :menus="table.datas"
    :parent_id="table.form.parent_id"
    @done="getDatas"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import {
  menuQuery,
  menuDelete,
  menuUpdate,
  menuUpdateSort,
} from '@/api/admin/menu';
import Popconfirm from '@/components/popconfirm/index.vue';
import Table from '@/components/table/index.vue';
import Types from '@/components/types/index.vue';
import MenuEdit from './menu-edit.vue';

onMounted(() => {
  getDatas();
});

// 数据
const table = reactive({
  form: {
    parent_id: 0,
  },
  cates: [], // 类别
  datas: [], // 表数据
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
      dataIndex: 'url',
      title: '链接',
      width: 80,
      slotName: 'url',
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
      width: 50,
      fixed: 'right',
      slotName: 'operation',
    },
  ],
});

// 是否打开弹窗
const showEdit = ref(false);

// 当前编辑的数据
const currentData = ref();

// 切换状态
const changeStatusFun = (record) => {
  menuUpdate(record).then((res) => {
    Message.success('更新成功');
  });
};

const getDatas = async () => {
  const {
    data: { fields, data, cate },
  } = await menuQuery(table.form);
  table.fields = fields;
  table.datas = data;
  table.cates = cate;
};

const editMenu = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delMenu = (row) => {
  menuDelete(row).then((res) => {
    Message.success('删除成功');
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
  menuUpdateSort({ data: sortData }).then(() => {
    getDatas();
    Message.success('更改排序成功');
  });
  table.datas = data;
};
</script>
<style scoped>
.arco-typography {
  margin-bottom: 0;
}
</style>
