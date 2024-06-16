<template>
  <a-drawer
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改用户' : '添加用户'"
    width="45vw"
    :drawer-style="{
      minWidth: '360px',
    }"
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
      <a-tabs default-active-key="dept">
        <a-tab-pane key="dept" title="部门权限">
          <a-space direction="vertical" fill>
            <a-row :gutter="8">
              <a-col flex="auto">
                <a-select
                  allow-clear
                  allow-search
                  :fallback-option="false"
                  v-model="form.currentDept"
                  placeholder="选择部门..."
                  value-key="id"
                >
                  <template #prefix>部门</template>
                  <a-option v-for="dept of props.dept" :value="dept">
                    {{ dept.mark }}{{ dept.dept_name }}
                  </a-option>
                </a-select>
              </a-col>
              <a-col flex="60px">
                <a-button type="primary" @click="addUserDept">添加</a-button>
              </a-col>
            </a-row>
            <a-list size="medium">
              <a-list-item
                v-for="dept in form.dept"
                :key="dept.id"
                :action-layout="
                  useWindowWidthSize(500, 'vertical', 'horizontal')
                "
              >
                <a-list-item-meta :title="dept.dept_name" />
                <template #actions>
                  <a-space>
                    <a-select
                      v-model="dept.auth_range"
                      size="mini"
                      style="width: 150px"
                    >
                      <a-option :value="0">本人</a-option>
                      <a-option :value="1">本部门</a-option>
                      <a-option :value="2">本部门及下属部门</a-option>
                    </a-select>
                    <a-button
                      size="mini"
                      status="danger"
                      @click="delUserDept(dept.id)"
                    >
                      移除
                    </a-button>
                  </a-space>
                </template>
              </a-list-item>
            </a-list>
          </a-space>
        </a-tab-pane>
      </a-tabs>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { userCreate, userUpdate } from '@/api/admin/user.js';
import { useWindowWidthSize } from '@/hooks/window.js';
import { useResetForm } from '@/hooks/form.js';
import { deepClone } from '@/utils/utils.js';

const props = defineProps({
  visible: false,
  data: undefined,
  dept: undefined,
});

const { form, isUpdate, setForm, resetForm } = useResetForm({
  id: undefined,
  nickname: undefined,
  username: undefined,
  password: undefined,
  phone: undefined,
  dept: [],
  currentDept: undefined,
});

const addUserDept = () => {
  if (
    form.currentDept == undefined ||
    form.dept.filter((n) => form.currentDept.id == n.id).length > 0
  ) {
    return true;
  }
  form.dept.push({
    id: form.currentDept.id,
    dept_name: form.currentDept.dept_name,
    auth_range: 0,
  });
};

const delUserDept = (id) => {
  for (let index in form.dept) {
    if (form.dept[index]['id'] == id) {
      form.dept.splice(index, 1);
    }
  }
};

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

<style lang="less">
.arco-list-wrapper {
  .arco-list-item {
    padding: 10px !important;
  }
}
</style>
