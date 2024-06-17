<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改配置' : '添加配置'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="key">
        <a-input v-model="form.key" placeholder="请输入键(名称)...">
          <template #prefix>键</template>
        </a-input>
      </a-form-item>
      <a-form-item field="val">
        <a-input v-model="form.val" placeholder="请输入值...">
          <template #prefix>值</template>
        </a-input>
      </a-form-item>
      <a-form-item field="desc">
        <a-input v-model="form.desc" placeholder="请输入备注...">
          <template #prefix>备注</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { configCreate, configUpdate } from '@/api/admin/config.js';
import { assignObject } from '@/utils/utils.js';

const props = defineProps({
  visible: false,
  data: undefined,
  cate_name: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    cate_name: undefined,
    key: undefined,
    val: undefined,
    desc: undefined,
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
  form.cate_name = props.cate_name;
  if (isUpdate.value) {
    await configUpdate(form).then((res) => {
      Message.success('修改成功');
    });
  } else {
    await configCreate(form).then((res) => {
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
