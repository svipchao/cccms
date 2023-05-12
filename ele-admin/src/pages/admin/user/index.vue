<template>
  <Pane size="300px" >
    <template #left>123</template>
    <template #right>
      <div style="height:250px;border:1px solid red"></div>
    </template>
  </Pane>
</template>

<script setup>
import { ref, reactive, onMounted } from "vue";
import Pane from "@/components/pane/index.vue";
</script>
