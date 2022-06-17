<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改组织' : '添加组织'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="group_id">
        <a-select
          allow-clear
          v-model="form.group_id"
          placeholder="选择父级组织..."
          :fallback-option="false"
        >
          <template #prefix>父级组织</template>
          <a-option v-for="group in props.groups" :value="group.id" :label="group.group_name">
            {{ group.mark }}{{ group.group_name }}
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="admin_ids">
        <a-select
          allow-clear
          allow-search
          multiple
          v-model="form.admin_ids"
          placeholder="添加组织管理员..."
          :fallback-option="false"
          @search="searchUser"
        >
          <template #prefix>管理员</template>
          <a-option v-for="user in userList" :value="user.id">
            {{ user.nickname }}({{ user.username }})
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="group_name">
        <a-input v-model="form.group_name" placeholder="名称...">
          <template #prefix>组织名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="group_desc">
        <a-input v-model="form.group_desc" placeholder="备注...">
          <template #prefix>组织备注</template>
        </a-input>
      </a-form-item>
      <a-form-item field="roles">
        <a-select
          allow-clear
          v-model="form.role_ids"
          placeholder="选择角色..."
          :fallback-option="false"
          multiple
        >
          <template #prefix>选择角色</template>
          <a-option v-for="role in props.roles" :value="role.id" :label="role.role_name">
            {{ role.mark }}{{ role.role_name }}
          </a-option>
        </a-select>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import { groupQuery, groupCreate, groupUpdate } from "@/api/admin/group";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
  data: undefined,
  roles: undefined,
  groups: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    admin_ids: undefined,
    role_ids: undefined,
    group_id: undefined,
    group_name: "",
    group_desc: "",
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
    await groupUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await groupCreate(form).then((res) => {
      Message.success("添加成功");
    });
  }
  emit("done");
  emit("update:visible");
};

const userList = ref();

const searchUser = async (value) => {
  const { data } = await groupQuery({ user: value });
  assignObject(data, userList.value);
  userList.value = data;
};

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      if (props.data) {
        assignObject(form, props.data);
        userList.value = props.data.adminUsers;
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
