<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改类别' : '添加类别'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="type">
        <a-select
          allow-clear
          v-model="form.type"
          placeholder="选择类别标识..."
          :fallback-option="false"
        >
          <template #prefix>类别标识</template>
          <a-option v-for="(type, index) in props.type" :key="index" :value="index">
            {{ type }}
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="name">
        <a-input v-model="form.name" placeholder="请输入类别名称...">
          <template #prefix>类别名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="alias">
        <a-input v-model="form.alias" placeholder="请输入类别别名...">
          <template #prefix>类别别名</template>
        </a-input>
      </a-form-item>
      <a-form-item field="sort">
        <a-input-number v-model="form.sort" placeholder="请输入排序，数字越大越靠前...">
          <template #prefix>排序</template>
        </a-input-number>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import { typesCreate, typesUpdate } from "@/api/admin/types";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
  data: undefined,
  type: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    type: undefined,
    name: undefined,
    alias: undefined,
    sort: undefined,
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
    await typesUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await typesCreate(form).then((res) => {
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
