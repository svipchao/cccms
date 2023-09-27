<template>
  <a-modal
    :mask-closable="false"
    :visible="visible"
    :title="isUpdate ? '修改菜单' : '添加菜单'"
    @cancel="cancelModal"
    @ok="okModal"
  >
    <a-form :model="form" layout="vertical">
      <a-form-item field="menu_id">
        <a-select
          allow-clear
          v-model="form.menu_id"
          placeholder="选择父级菜单..."
          :fallback-option="false"
        >
          <template #prefix>父级菜单</template>
          <a-option
            v-for="menu in props.menus"
            :value="menu.id"
            :label="menu.name"
          >
            <i :class="['iconfont', menu.icon]"></i>
            {{ menu.mark }}{{ menu.name }}
          </a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="name">
        <a-input v-model="form.name" placeholder="请输入名称...">
          <template #prefix>菜单名称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="icon">
        <a-input v-model="form.icon" placeholder="请输入图标...">
          <template #prefix>菜单图标</template>
        </a-input>
      </a-form-item>
      <a-form-item field="url">
        <a-input v-model="form.url" placeholder="请输入菜单链接...">
          <template #prefix>菜单链接</template>
        </a-input>
      </a-form-item>
      <a-form-item field="node">
        <a-input v-model="form.node" placeholder="请输入权限节点...">
          <template #prefix>权限节点</template>
        </a-input>
      </a-form-item>
      <a-form-item field="sort">
        <a-input-number v-model="form.sort" placeholder="数字越大越靠前...">
          <template #prefix>菜单排序</template>
        </a-input-number>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { Message } from "@arco-design/web-vue";
import { menuCreate, menuUpdate } from "@/api/admin/menu.js";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
  data: undefined,
  menus: undefined,
  type_id: undefined,
});

const getFormInit = () => {
  return {
    id: undefined,
    type_id: undefined,
    menu_id: undefined,
    name: undefined,
    icon: undefined,
    url: undefined,
    node: undefined,
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
  form.type_id = props.type_id;
  if (isUpdate.value) {
    await menuUpdate(form).then((res) => {
      Message.success("修改成功");
    });
  } else {
    await menuCreate(form).then((res) => {
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
