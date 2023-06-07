<template>
  <a-button @click="themeStore.switchTheme()">切换</a-button>
</template>

<script setup>
import { useTheme } from "@/store/admin/theme.js";

const themeStore = useTheme();
</script>
