<template>
  <a-drawer
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改角色' : '添加角色'"
    width="45vw"
    :drawer-style="{
      minWidth: '360px',
    }"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="role">
        <a-select
          allow-clear
          :allow-clear="true"
          :allow-search="true"
          v-model="form.role_id"
          :fallback-option="false"
          placeholder="选择父级角色..."
        >
          <template #prefix>父级角色</template>
          <a-option v-for="role of props.role" :value="role.id">
            {{ role.mark }}{{ role.role_name }}
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="role_name">
        <a-input v-model="form.role_name" placeholder="请输入角色名称...">
          <template #prefix>角色名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="role_desc">
        <a-input v-model="form.role_desc" placeholder="请输入角色备注...">
          <template #prefix>角色备注</template>
        </a-input>
      </a-form-item>
      <a-divider orientation="center">节点授权</a-divider>
      <a-form-item field="nodes">
        <Nodes :nodes="props.nodes" v-model:checkedNodes="form.nodes" />
      </a-form-item>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { roleCreate, roleUpdate } from '@/api/admin/role.js';
import { useResetForm } from '@/hooks/form.js';
import Nodes from '@/components/nodes/index.vue';

const props = defineProps({
  visible: false,
  data: undefined,
  role: undefined,
  nodes: undefined,
});

const { form, isUpdate, setForm, resetForm } = useResetForm({
  id: undefined,
  role_id: null,
  role_name: '',
  role_desc: '',
  nodes: [],
});

const emit = defineEmits(['update:visible', 'done']);

const cancelModal = () => {
  emit('update:visible', false);
};

const okModal = async () => {
  if (isUpdate.value) {
    await roleUpdate(form).then((res) => {
      Message.success('修改成功');
    });
  } else {
    await roleCreate(form).then((res) => {
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
