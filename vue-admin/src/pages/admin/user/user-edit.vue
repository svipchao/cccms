<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改用户' : '添加用户'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="group_ids">
        <a-select
          allow-clear
          multiple
          :maxTagCount="2"
          v-model="form.group_ids"
          placeholder="选择当前组织..."
          :fallback-option="
            () => {
              return {
                value: undefined,
                label: '',
              };
            }
          "
        >
          <template #prefix>当前组织</template>
          <a-option v-for="group in props.groups" :value="group.id" :label="group.group_name">
            {{ group.mark }}{{ group.group_name }}
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="type">
        <a-select
          allow-clear
          v-model="form.type"
          placeholder="选择用户类型..."
          :fallback-option="
            () => {
              return {
                value: undefined,
                label: '',
              };
            }
          "
        >
          <template #prefix>用户类型</template>
          <a-option v-for="(type, index) in props.types" :value="index" :label="type" />
        </a-select>
      </a-form-item>
      <a-form-item field="nickname">
        <a-input v-model="form.nickname" placeholder="请输入昵称...">
          <template #prefix>用户昵称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="username">
        <a-input v-model="form.username" placeholder="请输入用户名...">
          <template #prefix>用户账号</template>
        </a-input>
      </a-form-item>
      <a-form-item field="password">
        <a-input v-model="form.password" placeholder="请输入密码，留空不修改...">
          <template #prefix>用户密码</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import { userCreate, userUpdate } from "@/api/admin/user.js";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
  data: undefined,
  groups: undefined,
  types: undefined, // 用户类型
});

const getFormInit = () => {
  return {
    id: undefined,
    type: undefined, // 用户类型
    group_ids: undefined,
    nickname: undefined,
    username: undefined,
    password: undefined,
  };
};
const form = reactive(getFormInit());
const resetForm = () => {
  Object.assign(form, getFormInit());
};

const emit = defineEmits(["update:visible", "done"]);

const isUpdate = ref(true);

const cancelModal = () => {
  emit("update:visible", false);
};

const okModal = async () => {
  if (isUpdate.value) {
    if (form.password === null) {
      delete form.password;
    }
    await userUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await userCreate(form).then((res) => {
      Message.success("添加成功");
    });
  }
  emit("done");
  emit("update:visible");
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
