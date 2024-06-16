<template>
  <a-space direction="vertical" fill>
    <a-card :bordered="false" hoverable class="console">控制台</a-card>
    <a-card :bordered="false" title="更新日志">
      <a-timeline mode="left" label-position="relative">
        <a-timeline-item v-for="log in logs" :label="log.time">
          <b>{{ log.version }}</b>
          <div v-for="content in log.content">{{ content }}</div>
        </a-timeline-item>
      </a-timeline>
    </a-card>
  </a-space>
</template>

<script setup>
import { ref } from 'vue';

const logs = ref([
  { time: '2023-02-04', version: 'v1.0.0', content: ['初始化版本'] },
]);
</script>

<style scoped>
.console {
  display: flex;
  text-align: center;
  align-items: center;
  justify-content: center;
  padding: 20px 0;
  font-size: 1.6rem;
  color: #666;
  font-weight: bold;
}
</style>
