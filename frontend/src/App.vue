<script setup lang="ts">
import { RouterView } from 'vue-router'
import { dateFormat } from '@/composables/useFormatters'
</script>

<template>
  <RouterView :key="dateFormat" />
</template>
