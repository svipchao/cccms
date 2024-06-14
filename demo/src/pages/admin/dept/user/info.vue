<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改用户' : '添加用户'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="dept_ids">
        <a-tree-select
          v-if="props.depts"
          v-model="form.dept_ids"
          :multiple="true"
          :allow-clear="true"
          :allow-search="true"
          :tree-check-strictly="false"
          :data="props.depts"
          :fieldNames="{
            key: 'id',
            title: 'dept_name',
            children: 'children',
          }"
          :max-tag-count="2"
          placeholder="拥有组织"
        >
          <template #prefix>拥有组织</template>
          <template #label="{ data }">
            <span v-if="data.label.indexOf('+') == -1">
              {{
                data.label.slice(0, 5) + (data.label.length > 5 ? '...' : '')
              }}
            </span>
            <span v-else>{{ data.label }}</span>
          </template>
        </a-tree-select>
      </a-form-item>
      <a-form-item field="role_ids">
        <a-tree-select
          v-if="props.roles"
          v-model="form.role_ids"
          :multiple="true"
          :allow-clear="true"
          :allow-search="true"
          :tree-check-strictly="false"
          :data="props.roles"
          :fieldNames="{
            key: 'id',
            title: 'role_name',
            children: 'children',
          }"
          :max-tag-count="2"
          placeholder="拥有角色"
        >
          <template #prefix>拥有角色</template>
          <template #label="{ data }">
            <span v-if="data.label.indexOf('+') == -1">
              {{
                data.label.slice(0, 5) + (data.label.length > 5 ? '...' : '')
              }}
            </span>
            <span v-else>{{ data.label }}</span>
          </template>
        </a-tree-select>
      </a-form-item>
      <a-form-item field="range">
        <a-select v-model="form.range" placeholder="权限范围">
          <template #prefix>权限范围</template>
          <a-option :value="0">本人</a-option>
          <a-option :value="1">本人及下属</a-option>
          <a-option :value="2">本部门</a-option>
          <a-option :value="3">本部门及下属部门</a-option>
          <a-option :value="4">全部</a-option>
        </a-select>
      </a-form-item>
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
import { watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { userCreate, userUpdate } from '@/api/admin/user.js';
import { useResetForm } from '@/hooks/form.js';

const props = defineProps({
  visible: false,
  data: undefined,
  depts: undefined,
  roles: undefined,
});

const { form, isUpdate, setForm, resetForm } = useResetForm({
  id: undefined,
  nickname: undefined,
  username: undefined,
  password: undefined,
  phone: undefined,
  email: undefined,
  range: undefined,
  role_ids: undefined,
  dept_ids: undefined,
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
      if (props.data?.phone == 0) {
        props.data.phone = undefined;
      }
      setForm(props.data);
    } else {
      resetForm();
    }
  }
);
</script>
