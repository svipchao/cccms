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
        <i :class="['iconfont', record.icon]"></i>
        <span>&nbsp;{{ record.mark }}{{ record.name }}</span>
      </template>
      <template #url="{ record }">
        <a-typography-paragraph
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          {{ record.url }}
        </a-typography-paragraph>
      </template>
      <template #node="{ record }">
        <a-typography-paragraph
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          {{ record.node }}
        </a-typography-paragraph>
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
        <a-typography-text
          type="primary"
          @click="editMenu(record)"
          v-permission="'admin/menu/update'"
        >
          编辑
        </a-typography-text>
        <a-typography-text
          type="danger"
          @click="delMenu(record)"
          v-permission="'admin/menu/delete'"
        >
          删除
        </a-typography-text>
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
    { dataIndex: 'id', title: 'ID', width: 50 },
    { dataIndex: 'name', title: '菜单名称', width: 200, slotName: 'name' },
    { dataIndex: 'url', title: '链接', width: 180, slotName: 'url' },
    { dataIndex: 'node', title: '权限节点', width: 180, slotName: 'node' },
    { dataIndex: 'sort', title: '排序', width: 80 },
    { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    { dataIndex: 'create_time', title: '创建时间', width: 180 },
    { dataIndex: 'update_time', title: '更新时间', width: 180 },
    {
      dataIndex: 'operation',
      title: '操作',
      width: 100,
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
