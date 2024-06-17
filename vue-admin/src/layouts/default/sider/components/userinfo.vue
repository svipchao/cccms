<template>
  <a-col flex="70px" style="text-align: center">
    <a-avatar :size="50" trigger-type="mask" @click="editUserInfo()">
      <template #trigger-icon>
        <i class="ri-edit-line" style="color: var(--color-white)"></i>
      </template>
      {{ userStore.getFirstNickname }}
    </a-avatar>
    <Info
      v-model:visible="userEditStatus.data.visible"
      :data="userEditStatus.data.currentData"
      @done="userStore.setAccessToken()"
    />
  </a-col>
  <a-col flex="auto">
    <a-typography-paragraph
      :ellipsis="{
        showTooltip: true,
      }"
      class="username"
    >
      {{ userStore.username }}
    </a-typography-paragraph>
    <a-typography-paragraph
      :ellipsis="{
        showTooltip: true,
      }"
      class="nickname"
    >
      {{ userStore.nickname }}
    </a-typography-paragraph>
  </a-col>
</template>
<script setup>
import { useUserStore } from '@/stores/admin/user.js';
import { useFormEdit } from '@/hooks/form.js';
import Info from './userinfo-edit.vue';

const userStore = useUserStore();

let userEditStatus = useFormEdit();

const editUserInfo = () => {
  userEditStatus.updateFormEditStatus({
    id: userStore.id,
    nickname: userStore.nickname,
    username: userStore.username,
    phone: userStore.phone,
    email: userStore.email,
  });
};
</script>
