<script setup lang="ts">
import { RouterView } from 'vue-router'
import { dateFormat } from '@/composables/useFormatters'
import ToastContainer from '@/components/common/ToastContainer.vue'
</script>

<template>
  <RouterView :key="dateFormat" />
  <ToastContainer />
</template>
