<template>
  <a-card>
    <a-tabs
      :active-key="table.form.cate_id"
      @change="switchCate"
      hide-content
      editable
      show-add-button
      style="padding-bottom: 10px"
    >
      <a-tab-pane
        v-for="(cate, index) in table.cate"
        :key="cate.id"
        :title="cate.cate_name"
      />
    </a-tabs>
    <Table
      :fields="table.fields"
      :ignoreFields="table.ignoreFields"
      v-model:recycle="table.form.recycle"
      v-model:form="table.form"
      v-model:columns="table.columns"
      v-model:pagination="table.pagination"
      :data="table.datas"
      @reload="getDatas"
    >
      <template #headerButton>
        <a-space>
          <a-upload
            :custom-request="addFile"
            :show-file-list="false"
            v-permission="'admin/file/create'"
          />
        </a-space>
      </template>
      <template #userFilter>
        <a-card :style="{ width: '200px' }">
          <a-input
            v-model="table.form.user"
            placeholder="请输入用户账号或昵称"
          />
          <template #actions>
            <a-button size="mini" type="primary" @click="getDatas">
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
          <span>
            {{ record.nickname || '未知' }}({{ record.username || '未知' }})
          </span>
        </a-typography-paragraph>
      </template>
      <template #fileName="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          <span>{{ record.file_name }}</span>
        </a-typography-paragraph>
      </template>
      <template #fileUrl="{ record, rowIndex }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
          :copy-text="record.file_link"
        >
          <template #copy-tooltip>复制外部链接</template>
          <span @click="showImage(rowIndex)">预览</span>
        </a-typography-paragraph>
      </template>
      <template #status="{ record, rowIndex }">
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
          @click="editFile(record)"
          v-permission="'admin/file/update'"
        >
          <template #icon>
            <i class="ri-edit-line"></i>
          </template>
        </a-button>
        <Popconfirm
          content="确定要删除吗？"
          type="warning"
          position="left"
          @ok="delFile(record)"
        >
          <a-button type="text" size="mini" v-permission="'admin/file/delete'">
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
      v-model:visible="fileEditStatus.data.visible"
      :data="fileEditStatus.data.currentData"
      :cate_id="table.form.cate_id"
      @done="getDatas"
    />
    <ImagePreviewGroup
      v-model:visible="image.visible"
      :current="image.current"
      :list="image.list"
    />
  </a-card>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import {
  fileQuery,
  fileDelete,
  fileCreate,
  fileUpdate,
} from '@/api/admin/file.js';
import { useFormEdit } from '@/hooks/form.js';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import ImagePreviewGroup from '@/components/image/image-preview-group.vue';
import Info from './info.vue';

// 切换状态
const changeStatusFun = (record) => {
  fileUpdate(record).then((res) => {
    Message.success('更新成功');
  });
};

const switchCate = (key) => {
  table.form.cate_id = key;
  getDatas();
};

const getDatas = async () => {
  const { data } = await fileQuery({ ...table.form, ...table.pagination });
  table.cate = data.cate;
  table.data = data.data;
  table.fields = data.fields;
  table.form.total = data.total;
  // 图片预览
  for (const index in table.data) {
    image.list.push(table.data[index].file_link);
  }
};

let fileEditStatus = useFormEdit();

const editFile = (row) => {
  fileEditStatus.updateFormEditStatus(row);
};

const delFile = (row) => {
  if (table.datas.length === 1) {
    if (table.form.page > 1) {
      table.form.page--;
    } else {
      table.form.page = 1;
    }
  }
  fileDelete(row).then((res) => {
    Message.success('删除成功');
    getDatas();
  });
};

const addFile = (option) => {
  const { fileItem, name } = option;
  const formData = new FormData();
  formData.append(name || 'file', fileItem.file);
  formData.append(name || 'file_name', fileItem.name);
  formData.append(name || 'cate_id', table.form.cate_id);
  fileCreate(formData).then((res) => {
    Message.success('上传成功');
    getDatas();
  });
};

// 图片预览
const image = reactive({
  visible: false,
  list: [],
  current: 0,
});

const showImage = (rowIndex) => {
  image.current = rowIndex;
  image.visible = true;
};

// 数据
const table = reactive({
  form: {
    recycle: false,
    cate_id: 1,
    user: undefined,
  },
  pagination: {
    page: 1,
    limit: 15,
    total: 0,
  },
  user: [],
  cate: [], // 类别
  data: [], // 表数据
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
      dataIndex: 'file_name',
      title: '文件名',
      width: 230,
      slotName: 'fileName',
    },
    {
      dataIndex: 'file_url',
      title: '外链地址',
      width: 200,
      slotName: 'fileUrl',
    },
    { dataIndex: 'file_size', title: '文件大小', width: 100 },
    { dataIndex: 'status', title: '状态', width: 80, slotName: 'status' },
    { dataIndex: 'create_time', title: '创建时间', width: 180 },
    { dataIndex: 'update_time', title: '更新时间', width: 180 },
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