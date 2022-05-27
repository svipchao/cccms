<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改路由' : '添加路由'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="alias">
        <a-input v-model="form.alias" placeholder="请输入别名...">
          <template #prefix>别名</template>
        </a-input>
      </a-form-item>
      <a-form-item field="url">
        <a-input v-model="form.url" placeholder="请输入URL...">
          <template #prefix>URL</template>
        </a-input>
      </a-form-item>
      <a-form-item field="ext">
        <a-input v-model="form.ext" placeholder="请输入链接后缀...">
          <template #prefix>后缀</template>
        </a-input>
      </a-form-item>
      <a-form-item field="name">
        <a-input v-model="form.name" placeholder="请输入路由标识...">
          <template #prefix>标识</template>
        </a-input>
      </a-form-item>
      <a-form-item field="desc">
        <a-input v-model="form.desc" placeholder="请输入备注...">
          <template #prefix>备注</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import { routeCreate, routeUpdate } from "@/api/admin/route.js";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
  data: undefined,
  routes: undefined,
  type_id: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    type_id: undefined,
    alias: undefined,
    url: undefined,
    name: undefined,
    ext: undefined,
    desc: undefined,
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
  form.type_id = props.type_id;
  if (isUpdate.value) {
    await routeUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await routeCreate(form).then((res) => {
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
