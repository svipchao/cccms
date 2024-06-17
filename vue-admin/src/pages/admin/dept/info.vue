<template>
  <a-drawer
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改部门' : '添加部门'"
    width="45vw"
    :drawer-style="{
      minWidth: '360px',
    }"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="dept">
        <a-select
          allow-clear
          :allow-clear="true"
          :allow-search="true"
          v-model="form.dept_id"
          :fallback-option="false"
          placeholder="选择父级部门..."
        >
          <template #prefix>父级部门</template>
          <a-option v-for="dept of props.dept" :value="dept.id">
            {{ dept.mark }}{{ dept.dept_name }}
          </a-option>
        </a-select>
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
      <a-form-item field="dept_id">
        <a-select
          multiple
          allow-clear
          allow-search
          :fallback-option="false"
          v-model="form.role"
          placeholder="选择角色..."
          value-key="id"
        >
          <template #prefix>拥有角色</template>
          <a-option v-for="role of props.role" :value="role">
            {{ role.mark }}{{ role.role_name }}
          </a-option>
        </a-select>
      </a-form-item>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { deptCreate, deptUpdate } from '@/api/admin/dept.js';
import { useResetForm } from '@/hooks/form.js';

const props = defineProps({
  visible: false,
  data: undefined,
  dept: undefined,
  role: undefined,
});

const { form, isUpdate, setForm, resetForm } = useResetForm({
  id: undefined,
  dept_id: null,
  dept_name: '',
  dept_desc: '',
  role: [],
  dept: [],
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
