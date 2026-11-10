<script setup lang="ts">
export type PveModulePage = 'revenue' | 'escalations'

defineProps<{
  active: PveModulePage
}>()
</script>

<template>
  <nav />
</template>
