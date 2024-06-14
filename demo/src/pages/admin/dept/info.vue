<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改部门' : '添加部门'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="dept_id">
        <a-tree-select
          :data="props.depts"
          v-model="form.dept_id"
          placeholder="选择部门..."
          :fallback-option="false"
          :fieldNames="{
            key: 'id',
            title: 'dept_name',
            children: 'children',
          }"
        >
          <template #prefix>父级部门</template>
        </a-tree-select>
      </a-form-item>
      <a-form-item field="dept_name">
        <a-input v-model="form.dept_name" placeholder="请输入部门名称...">
          <template #prefix>部门名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="dept_desc">
        <a-input v-model="form.dept_desc" placeholder="请输入部门备注...">
          <template #prefix>部门备注</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
import { watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { deptCreate, deptUpdate } from '@/api/admin/dept.js';
import { useResetForm } from '@/hooks/form.js';

const props = defineProps({
  visible: false,
  data: undefined,
  depts: undefined,
  roles: undefined,
  nodes: undefined,
});

const { form, isUpdate, setForm, resetForm } = useResetForm({
  id: undefined,
  dept_id: undefined,
  dept_name: '',
  dept_desc: '',
  role_ids: [],
  nodes: [],
});

const emit = defineEmits(['update:visible', 'done']);

const cancelModal = () => {
  emit('update:visible', false);
};

const okModal = async () => {
  if (isUpdate.value) {
    await deptUpdate(form).then((res) => {
      Message.success('修改成功');
    });
  } else {
    await deptCreate(form).then((res) => {
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
      setForm(props.data);
    } else {
      resetForm();
    }
  }
);
</script>
