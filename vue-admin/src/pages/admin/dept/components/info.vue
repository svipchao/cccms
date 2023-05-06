<template>
  <a-drawer
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改部门' : '添加部门'"
    width="45vw"
    :drawer-style="{
      minWidth: '300px',
    }"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="dept_id">
        <a-select
          allow-clear
          v-model="form.dept_id"
          placeholder="选择部门..."
          :fallback-option="false"
        >
          <template #prefix>父级部门</template>
          <a-option v-for="dept in props.depts" :value="dept.id" :label="dept.dept_name">
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
    </a-form>
  </a-drawer>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from "vue";
import { Message } from "@arco-design/web-vue";
import { deptCreate, deptUpdate } from "@/api/admin/dept.js";
import { useResetForm } from "@/hooks/form.js";

const props = defineProps({
  visible: false,
  data: undefined,
  depts: undefined,
});

const { form, setForm, resetForm } = useResetForm({
  id: undefined,
  dept_id: undefined,
  dept_name: "",
  dept_desc: "",
  nodes: [],
});

const isUpdate = ref(true);
const emit = defineEmits(["update:visible", "done"]);

const cancelModal = () => {
  emit("update:visible", false);
};

const okModal = async () => {
  if (isUpdate.value) {
    await deptUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await deptCreate(form).then((res) => {
      Message.success("添加成功");
    });
  }
  emit("done");
  emit("update:visible");
};

const nodes = ref([]);

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
