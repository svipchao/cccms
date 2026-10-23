<template>
  <ErrorPage :code="404" title-key="core.error.notFoundTitle" desc-key="core.error.notFoundDesc" />
</template>

<script setup lang="ts">
import ErrorPage from './ErrorPage.vue'
</script>
