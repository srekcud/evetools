<script setup lang="ts">
export type IndustryModulePage = 'workshop' | 'projects' | 'analysis' | 'production'

defineProps<{
  active: IndustryModulePage
}>()
</script>

<template>
  <div />
</template>
