<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改配置' : '添加配置'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="file_name">
        <a-input v-model="form.file_name" placeholder="请输入文件名称...">
          <template #prefix>文件名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="file_desc">
        <a-textarea placeholder="请输入文件备注..." v-model="form.file_desc" />
      </a-form-item>
      <a-form-item field="extract_code">
        <a-input v-model="form.extract_code" placeholder="请输入文件提取码...">
          <template #prefix>提取码</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { fileCreate, fileUpdate } from '@/api/admin/file.js';
import { assignObject } from '@/utils/utils.js';

const props = defineProps({
  visible: false,
  data: undefined,
  type_id: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    type_id: undefined,
    file_name: undefined,
    file_desc: undefined,
    extract_code: undefined,
  };
};
const form = reactive(getFormInit());
const resetForm = () => {
  Object.assign(form, getFormInit());
};

const emit = defineEmits(['update:visible', 'done']);

const isUpdate = ref(true);

const cancelModal = () => {
  emit('update:visible', false);
};

const okModal = async () => {
  form.type_id = props.type_id;
  if (isUpdate.value) {
    await fileUpdate(form).then((res) => {
      Message.success('修改成功');
    });
  } else {
    await fileCreate(form).then((res) => {
      Message.success('添加成功');
    });
  }
  emit('done');
  emit('update:visible');
};

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      if (props.data) {
        assignObject(form, props.data);
        isUpdate.value = true;
      } else {
        isUpdate.value = false;
      }
    } else {
      resetForm();
    }
  }
);
</script>
