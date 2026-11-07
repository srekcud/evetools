<script setup lang="ts">
import type { ProjectTemplate } from '@/stores/group-industry/types'

defineEmits<{
  use: [template: ProjectTemplate]
}>()
</script>

<template>
  <div data-testid="project-templates-panel" />
</template>
