<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改用户' : '添加用户'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="nickname">
        <a-input v-model="form.nickname" placeholder="请输入姓名...">
          <template #prefix>用户姓名</template>
        </a-input>
      </a-form-item>
      <a-form-item field="username">
        <a-input v-model="form.username" placeholder="请输入用户名...">
          <template #prefix>用户账号</template>
        </a-input>
      </a-form-item>
      <a-form-item field="password">
        <a-input
          v-model="form.password"
          placeholder="请输入密码，留空不修改..."
        >
          <template #prefix>用户密码</template>
        </a-input>
      </a-form-item>
      <a-form-item field="phone">
        <a-input-number v-model="form.phone" placeholder="请输入手机号码...">
          <template #prefix>手机号码</template>
        </a-input-number>
      </a-form-item>
      <a-form-item field="email">
        <a-input v-model="form.email" placeholder="请输入电子邮箱地址...">
          <template #prefix>电子邮箱</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { userCreate, userUpdate } from '@/api/admin/user.js';
import { useResetForm } from '@/hooks/form.js';

const props = defineProps({
  visible: false,
  data: undefined,
  users: undefined,
  types: undefined, // 用户类型
});

const { form, isUpdate, setForm, resetForm } = useResetForm({
  id: undefined,
  nickname: undefined,
  username: undefined,
  password: undefined,
  phone: undefined,
  email: undefined,
});

const emit = defineEmits(['update:visible', 'done']);

const cancelModal = () => {
  emit('update:visible', false);
};

const okModal = async () => {
  if (isUpdate.value) {
    if (form.password === null) {
      delete form.password;
    }
    await userUpdate(form).then((res) => {
      Message.success('修改成功');
    });
  } else {
    await userCreate(form).then((res) => {
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
      if (props.data.phone == 0) {
        props.data.phone = undefined;
      }
      setForm(props.data);
    } else {
      resetForm();
    }
  }
);
</script>
