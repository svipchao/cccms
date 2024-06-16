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
          <template #suffix>
            <i :class="form.icon"></i>
          </template>
        </a-input>
        <a-button type="primary" @click="switchIcon">选择图标</a-button>
      </a-form-item>
      <a-form-item field="url">
        <a-input v-model="form.url" placeholder="请输入菜单链接...">
          <template #prefix>菜单链接</template>
        </a-input>
      </a-form-item>
      <a-form-item field="target">
        <a-select
          allow-clear
          v-model="form.target"
          placeholder="页面打开方式..."
          :fallback-option="false"
        >
          <template #prefix>打开方式</template>
          <a-option value="_self">当前页面</a-option>
          <a-option value="_blank">新建页面</a-option>
        </a-select>
      </a-form-item>
      <a-form-item field="node">
        <a-input v-model="form.node" placeholder="请输入权限节点...">
          <template #prefix>权限节点</template>
        </a-input>
      </a-form-item>
    </a-form>
    <CIcon v-model:visible="showIcon" v-model:icon="form.icon"></CIcon>
  </a-modal>
</template>
<script setup>
import { ref, watch } from 'vue';
import { Message } from '@arco-design/web-vue';
import { menuCreate, menuUpdate } from '@/api/admin/menu.js';
import { useUserStore } from '@/stores/admin/user.js';
import { useResetForm } from '@/hooks/form.js';
import CIcon from '@/components/icons/index.vue';

const props = defineProps({
  visible: false,
  data: undefined,
  menus: undefined,
  parent_id: undefined,
});

const { form, isUpdate, setForm, resetForm } = useResetForm({
  id: undefined,
  parent_id: undefined,
  menu_id: undefined,
  name: undefined,
  icon: undefined,
  url: undefined,
  target: '_self',
  node: undefined,
});

const showIcon = ref(false);
const switchIcon = () => {
  showIcon.value = !showIcon.value;
};

const emit = defineEmits(['update:visible', 'done']);

const cancelModal = () => {
  emit('update:visible', false);
};

const okModal = async () => {
  form.parent_id = props.parent_id;
  if (isUpdate.value) {
    await menuUpdate(form).then((res) => {
      Message.success('修改成功');
    });
  } else {
    await menuCreate(form).then((res) => {
      Message.success('添加成功');
    });
  }
  useUserStore().setAccessToken();
  emit('done');
  emit('update:visible');
};

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      setForm(props.data);
    } else {
      resetForm();
    }
  }
);
</script>
