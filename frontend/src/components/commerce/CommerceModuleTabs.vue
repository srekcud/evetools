<script setup lang="ts">
export type CommerceModulePage = 'market' | 'appraisal' | 'contracts'

defineProps<{
  active: CommerceModulePage
}>()
</script>

<template>
  <nav />
</template>
