<template>
  <a-drawer
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改角色' : '添加角色'"
    width="45vw"
    :drawer-style="{
      minWidth: '300px',
    }"
    @cancel="cancelModal"
    @ok="okModal"
    @before-open="getNodes"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="role_id">
        <a-select
          allow-clear
          v-model="form.role_id"
          placeholder="选择角色..."
          :fallback-option="false"
          @change="getNodes"
        >
          <template #prefix>父级角色</template>
          <a-option v-for="role in props.roles" :value="role.id" :label="role.role_name">
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
      <a-form-item>
        <a-tree
          v-if="nodes.length > 0"
          checkable
          default-expand-all
          v-model:checked-keys="form.nodes"
          :data="nodes"
          :fieldNames="{
            label: 'parentNode',
            key: 'currentNode',
            title: 'title',
            children: 'children',
          }"
        />
        <a-empty v-else />
      </a-form-item>
    </a-form>
  </a-drawer>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from "vue";
import { ElMessage } from "element-plus";
import { roleCreate, roleUpdate, authQuery } from "@/api/admin/role.js";
import { useResetForm } from "@/hooks/form.js";

const props = defineProps({
  visible: false,
  data: undefined,
  roles: undefined,
});

const { form, setForm, resetForm } = useResetForm({
  id: undefined,
  role_id: undefined,
  role_name: "",
  role_desc: "",
  nodes: [],
});

const isUpdate = ref(true);
const emit = defineEmits(["update:visible", "done"]);

const cancelModal = () => {
  emit("update:visible", false);
};

const okModal = async () => {
  if (isUpdate.value) {
    await roleUpdate(form).then((res) => {
      ElMessage.success("修改成功");
    });
  } else {
    await roleCreate(form).then((res) => {
      ElMessage.success("添加成功");
    });
  }
  emit("done");
  emit("update:visible");
};

const nodes = ref([]);

const getNodes = async () => {
  const { data } = await authQuery({ role_id: form.role_id });
  nodes.value = data;
};

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      if (props.data) {
        setForm(props.data);
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

<style scoped lang="less">
:deep(.arco-tree) {
  width: 100%;
  height: calc(100vh - 386px);
  overflow-y: auto;
  &::-webkit-scrollbar {
    width: 8px;
    height: 8px;
  }
  &::-webkit-scrollbar-track {
    border-radius: 8px;
  }
  &::-webkit-scrollbar-thumb {
    border-radius: 8px;
    background: var(--color-neutral-3);
  }
}
:deep(.arco-tree-node-is-leaf) {
  display: inline-flex;
}
</style>
