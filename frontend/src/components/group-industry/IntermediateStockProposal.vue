<script setup lang="ts">
import type { GroupProjectStatus } from '@/stores/group-industry/types'

defineProps<{
  projectId: string
  projectStatus: GroupProjectStatus
}>()
</script>

<template>
  <section data-testid="intermediate-stock-proposal" />
</template>
