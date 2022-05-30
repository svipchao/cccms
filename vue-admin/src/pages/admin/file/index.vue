<template>
  <a-card>
    <types v-model:type_id="tableInfo.form.type_id" :types="tableInfo.types" @reload="getFiles" />
    <table-header v-model:columns="tableInfo.tableColumns" @reload="getFiles">
      <template #left>
        <a-space>
          <a-upload
            :custom-request="addFile"
            :show-file-list="false"
            v-permission="'admin/file/create'"
          />
        </a-space>
      </template>
    </table-header>
    <a-table
      :columns="tableInfo.tableColumns"
      :data="tableInfo.tableDatas"
      row-key="id"
      :scroll="{
        x: 900,
      }"
      :pagination="{
        total: tableInfo.form.total,
        current: tableInfo.form.page,
        pageSize: tableInfo.form.limit,
      }"
      @page-change="handlePageChange"
    >
      <template #userFilter>
        <a-card :style="{ width: '200px' }">
          <a-input v-model="tableInfo.form.user" placeholder="请输入用户账号或昵称" />
          <template #actions>
            <a-button size="mini" type="primary" @click="getFiles">确定</a-button>
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
          <span>{{ record.nickname || "未知" }}({{ record.username || "未知" }})</span>
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
        <a-typography-text
          type="primary"
          @click="editFile(record)"
          v-permission="'admin/file/update'"
        >
          编辑
        </a-typography-text>
        <a-typography-text
          type="danger"
          @click="delFile(record)"
          v-permission="'admin/file/delete'"
        >
          删除
        </a-typography-text>
      </template>
    </a-table>
  </a-card>
  <file-edit
    v-model:visible="showEdit"
    :data="currentData"
    :type_id="tableInfo.form.type_id"
    @done="getFiles"
  />
  <image-preview-group
    v-model:visible="image.visible"
    :current="image.current"
    :list="image.list"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { fileQuery, fileDelete, fileCreate, fileUpdate } from "@/api/admin/file";
import Types from "@/components/types/index.vue";
import ImagePreviewGroup from "@/components/image/image-preview-group.vue";
import TableHeader from "@/components/table/table-header.vue";
import FileEdit from "./file-edit.vue";
import { tableField } from "@/utils/utils.js";

onMounted(() => {
  getFiles();
});

// 数据
const tableInfo = reactive({
  form: {
    type_id: 0,
    user: undefined,
    page: 1,
    limit: 10,
    total: 0,
  },
  users: [],
  types: [], // 类别
  tableDatas: [], // 表数据
  tableColumns: [
    { dataIndex: "id", title: "ID", width: 50 },
    {
      dataIndex: "user_id",
      title: "用户昵称(账号)",
      width: 160,
      slotName: "user",
      filterable: {
        slotName: "userFilter",
      },
    },
    { dataIndex: "file_name", title: "文件名", width: 230, slotName: "fileName" },
    { dataIndex: "file_url", title: "外链地址", width: 200, slotName: "fileUrl" },
    { dataIndex: "file_size", title: "文件大小", width: 100 },
    { dataIndex: "status", title: "状态", width: 80, slotName: "status" },
    { dataIndex: "create_time", title: "创建时间", width: 180 },
    { dataIndex: "update_time", title: "更新时间", width: 180 },
    { dataIndex: "operation", title: "操作", width: 100, fixed: "right", slotName: "operation" },
  ],
});

// 是否打开弹窗
const showEdit = ref(false);

// 当前编辑的数据
const currentData = ref();

const handlePageChange = (page) => {
  tableInfo.form.page = page;
  getFiles();
};

// 切换状态
const changeStatusFun = (record) => {
  fileUpdate(record).then((res) => {
    Message.success("更新成功");
  });
};

const getFiles = async () => {
  const {
    data: { fields, data, types, total },
  } = await fileQuery(tableInfo.form);
  tableInfo.tableColumns = tableField(tableInfo.tableColumns, Object.keys(fields), ["operation"]);
  tableInfo.types = types;
  tableInfo.tableDatas = data;
  tableInfo.form.total = total;

  // 图片预览
  for (const index in tableInfo.tableDatas) {
    image.list.push(tableInfo.tableDatas[index].file_link);
  }
};

const editFile = (row) => {
  showEdit.value = true;
  currentData.value = row;
};

const delFile = (row) => {
  if (tableInfo.tableDatas.length === 1) {
    if (tableInfo.form.page > 1) {
      tableInfo.form.page--;
    } else {
      tableInfo.form.page = 1;
    }
  }
  fileDelete(row).then((res) => {
    Message.success("删除成功");
    getFiles();
  });
};

const addFile = (option) => {
  const { fileItem, name } = option;
  const formData = new FormData();
  formData.append(name || "file", fileItem.file);
  formData.append(name || "file_name", fileItem.name);
  formData.append(name || "type_id", tableInfo.form.type_id);
  fileCreate(formData).then((res) => {
    Message.success("上传成功");
    getFiles();
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
</script>
