<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改权限' : '添加权限'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="role_id">
        <a-select
          allow-clear
          v-model="form.role_id"
          placeholder="选择角色..."
          :fallback-option="false"
        >
          <template #prefix>角色</template>
          <a-option v-for="role in props.roles" :value="role.id" :label="role.role_name">
            {{ role.mark }}{{ role.role_name }}
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="table">
        <a-select
          allow-clear
          v-model:model-value="form.table"
          placeholder="选择数据表..."
          @change="onSelectedTable"
        >
          <template #prefix>数据表</template>
          <a-option
            v-for="table in props.table"
            :value="table.table"
            :label="table.table_name + '(' + table.table + ')'"
          />
        </a-select>
      </a-form-item>
      <a-form-item field="field">
        <a-select allow-clear v-model:model-value="form.field" placeholder="选择表字段...">
          <template #prefix>表字段</template>
          <a-option v-for="(field, index) in fields" :value="index">
            {{ field }}({{ index }})
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="where">
        <a-input v-model="form.where" placeholder="输入条件...">
          <template #prefix>条件</template>
        </a-input>
      </a-form-item>
      <a-form-item field="value">
        <a-input v-model="form.value" placeholder="输入值...">
          <template #prefix>值</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import { dataCreate, dataUpdate } from "@/api/admin/data.js";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
  data: undefined,
  roles: undefined,
  table: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    table: undefined,
    role_id: undefined,
    field: "",
    where: "",
    value: "",
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

const fields = ref();

// 选择表 赋值表字段
const onSelectedTable = () => {
  if (!isUpdate.value) {
    form.field = "";
  }
  fields.value = props.table[form.table]?.fields;
};

const okModal = async () => {
  if (isUpdate.value) {
    await dataUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await dataCreate(form).then((res) => {
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
      // 修复修改的时候字段信息不存在问题
      onSelectedTable();
    } else {
      resetForm();
    }
  }
);
</script>
