<template>
  <a-card>
    <Table
      hideCardBorder
      :fields="table.fields"
      :ignoreFields="table.ignoreFields"
      v-model:columns="table.columns"
      v-model:pagination="table.pagination"
      :data="table.datas"
      row-key="id"
      @reload="getLogs"
    >
      <template #headerButton>
        <a-popconfirm
          content="是否清空30天前的日志？"
          @ok="delLog()"
          type="warning"
        >
          <a-button
            type="primary"
            status="danger"
            v-permission="'admin/log/delete'"
          >
            清空30天前日志
          </a-button>
        </a-popconfirm>
      </template>
      <template #userFilter>
        <a-card :style="{ width: '200px' }">
          <a-input
            v-model="table.form.user"
            placeholder="请输入用户账号或昵称"
          />
          <template #actions>
            <a-button size="mini" type="primary" @click="getLogs">
              确定
            </a-button>
          </template>
        </a-card>
      </template>
      <template #methodFilter>
        <a-card :style="{ width: '200px' }">
          <a-select
            v-model="table.form.req_method"
            placeholder="请选择请求类型..."
            allow-clear
          >
            <a-option value="GET">GET</a-option>
            <a-option value="HEAD">HEAD</a-option>
            <a-option value="POST">POST</a-option>
            <a-option value="PUT">PUT</a-option>
            <a-option value="DELETE">DELETE</a-option>
            <a-option value="CONNECT">CONNECT</a-option>
            <a-option value="OPTIONS">OPTIONS</a-option>
            <a-option value="TRACE">TRACE</a-option>
            <a-option value="PATCH">PATCH</a-option>
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getLogs">
              确定
            </a-button>
          </template>
        </a-card>
      </template>
      <template #paramFilter>
        <a-card :style="{ width: '200px' }">
          <a-input
            v-model="table.form.req_param"
            placeholder="模糊查询请求参数..."
          />
          <template #actions>
            <a-button size="mini" type="primary" @click="getLogs">
              确定
            </a-button>
          </template>
        </a-card>
      </template>
      <template #user="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          <span>{{ record.nickname }}({{ record.username }})</span>
        </a-typography-paragraph>
      </template>
      <template #reqParam="{ record }">
        <a-button
          type="primary"
          size="mini"
          @click="showParamModal(record.req_param)"
        >
          查看
        </a-button>
      </template>
      <template #reqIp="{ record }">
        <a-typography-paragraph
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          {{ record.req_ip }}
        </a-typography-paragraph>
      </template>
      <template #reqUa="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          {{ record.req_ua }}
        </a-typography-paragraph>
      </template>
    </Table>
    <a-modal
      :visible="paramVisible"
      @cancel="cancelParamModal"
      :closable="false"
      :footer="false"
    >
      <a-typography>
        <a-typography-paragraph blockquote>
          <pre>{{ JSON.parse(paramData) }}</pre>
        </a-typography-paragraph>
      </a-typography>
    </a-modal>
  </a-card>
</template>

<script setup>
import { reactive, ref, watch, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import { logQuery, logDelete } from '@/api/admin/log';
import Table from '@/components/table/index.vue';

onMounted(() => {
  getLogs();
});

// 数据
const table = reactive({
  form: {
    user: undefined,
    req_method: undefined,
    req_param: undefined,
  },
  pagination: {
    page: 1,
    limit: 15,
    total: 0,
  },
  users: [],
  datas: [],
  fields: [],
  ignoreFields: ['operation'],
  columns: [
    {
      dataIndex: 'user_id',
      title: '用户昵称(账号)',
      width: 160,
      slotName: 'user',
      filterable: {
        slotName: 'userFilter',
      },
    },
    {
      dataIndex: 'req_method',
      title: '请求类型',
      width: 90,
      filterable: {
        slotName: 'methodFilter',
      },
    },
    { dataIndex: 'name', title: '行为名称', width: 180 },
    { dataIndex: 'node', title: '操作节点', width: 250 },
    {
      dataIndex: 'req_param',
      title: '请求参数',
      width: 100,
      slotName: 'reqParam',
      filterable: {
        slotName: 'paramFilter',
      },
    },
    { dataIndex: 'req_ip', title: '请求IP', width: 150, slotName: 'reqIp' },
    { dataIndex: 'req_ua', title: 'User-Agent', width: 230, slotName: 'reqUa' },
    { dataIndex: 'create_time', title: '创建时间', width: 180 },
  ],
});

const getLogs = async () => {
  const {
    data: { fields, total, data },
  } = await logQuery({ ...table.form, ...table.pagination });
  table.datas = data;
  table.fields = fields;
  table.pagination.total = total;
};

const delLog = (row) => {
  logDelete(row).then((res) => {
    Message.success('删除成功');
    getLogs();
  });
};

// 查看参数弹窗
const paramVisible = ref(false);
const paramData = ref(null);

const showParamModal = (param) => {
  paramData.value = param;
  paramVisible.value = true;
};
const cancelParamModal = () => {
  paramVisible.value = false;
};
</script>
