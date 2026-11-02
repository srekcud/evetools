<script setup lang="ts">
import type { DataFreshnessSource } from '@/utils/dataFreshness'

defineProps<{
  sources: readonly DataFreshnessSource[]
}>()
</script>

<template>
  <div data-testid="data-freshness-list" />
</template>
