<script setup lang="ts">
defineProps<{
  show: boolean
  projectId: string
  defaultName: string
}>()

defineEmits<{
  close: []
  saved: []
}>()
</script>

<template>
  <div data-testid="save-project-template-modal" />
</template>
