<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

export interface ModuleTab {
  id: string
  label: string
  to: RouteLocationRaw
  count?: number
}

defineProps<{
  tabs: ModuleTab[]
  activeId?: string
}>()
</script>

<template>
  <div />
</template>
