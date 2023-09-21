<template>
  <a-col flex="70px" style="text-align: center">
    <a-avatar :size="50" trigger-type="mask" @click="() => {}">
      <template #trigger-icon>
        <i class="ri-edit-line" style="color: var(--color-white)"></i>
      </template>
      {{ userStore.getFirstNickname }}
    </a-avatar>
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
import { useUser } from '@/store/admin/user.js';

const userStore = useUser();
</script>
