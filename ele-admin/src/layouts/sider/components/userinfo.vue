<template>
  <el-col flex="70px" style="text-align: center">
    <el-avatar :size="50" trigger-type="mask" @click="() => {}">
      <template #trigger-icon>
        <i class="ri-edit-line" style="color: var(--color-white)"></i>
      </template>
      {{ userStore.getFirstNickname }}
    </el-avatar>
  </el-col>
  <el-col flex="auto">
    <el-text
      :ellipsis="{
        showTooltip: true,
      }"
      class="username"
    >
      {{ userStore.username }}
    </el-text>
    <el-text
      :ellipsis="{
        showTooltip: true,
      }"
      class="nickname"
    >
      {{ userStore.nickname }}
    </el-text>
  </el-col>
</template>
<script setup>
import { useUser } from "@/store/admin/user.js";

const userStore = useUser();
</script>
