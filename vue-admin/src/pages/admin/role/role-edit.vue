<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改角色' : '添加角色'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="role_id">
        <a-select
          allow-clear
          v-model="form.role_id"
          placeholder="选择角色..."
          :fallback-option="
            () => {
              return {
                value: undefined,
                label: '',
              };
            }
          "
        >
          <template #prefix>父级角色</template>
          <a-option v-for="role in props.roles" :value="role.id" :label="role.role_name">
            {{ role.mark }}{{ role.role_name }}
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="role_name">
        <a-input v-model="form.role_name" placeholder="名称...">
          <template #prefix>角色名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="role_desc">
        <a-input v-model="form.role_desc" placeholder="备注...">
          <template #prefix>角色备注</template>
        </a-input>
      </a-form-item>
      <a-form-item>
        <a-tree-select
          v-model="form.nodes"
          :allow-clear="true"
          :tree-checkable="true"
          :max-tag-count="3"
          :data="nodes"
          placeholder="选择角色权限..."
          :fieldNames="{
            label: 'parentNode',
            key: 'currentNode',
            title: 'title',
            children: 'children',
          }"
          @popupVisibleChange="onSelect"
          :fallback-option="undefined"
        >
          <template #prefix>角色权限</template>
        </a-tree-select>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import { roleCreate, roleUpdate, authQuery } from "@/api/admin/role.js";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
  data: undefined,
  roles: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    role_id: undefined,
    role_name: "",
    role_desc: "",
    nodes: [],
  };
};
const form = reactive(getFormInit());
const resetForm = () => {
  Object.assign(form, getFormInit());
};

const emit = defineEmits(["update:visible", "done"]);

const isUpdate = ref(true);

const nodes = ref();

const cancelModal = () => {
  emit("update:visible", false);
};

const okModal = async () => {
  if (isUpdate.value) {
    await roleUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await roleCreate(form).then((res) => {
      Message.success("添加成功");
    });
  }
  emit("done");
  emit("update:visible");
};

// select下拉状态
const onSelect = (visible) => {
  if (visible) {
    getNodes();
  }
};

const getNodes = async () => {
  const { data } = await authQuery({ role_id: form.role_id });
  nodes.value = data;
};

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      if (props.data) {
        assignObject(form, props.data);
        getNodes();
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
