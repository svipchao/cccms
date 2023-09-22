<template>
  <Panel size="300px">
    <template #left>123</template>
    <template #right>
      <div style="height: 250px; border: 1px solid red"></div>
    </template>
  </Panel>
</template>

<script setup>
import { ref, reactive, onMounted } from "vue";
import Panel from "@/components/panel/index.vue";
</script>
