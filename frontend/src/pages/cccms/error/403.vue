<template>
  <ErrorPage :code="403" title-key="core.error.forbiddenTitle" desc-key="core.error.forbiddenDesc" />
</template>

<script setup lang="ts">
import ErrorPage from './ErrorPage.vue'
</script>
