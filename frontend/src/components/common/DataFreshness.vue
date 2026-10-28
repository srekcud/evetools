<script setup lang="ts">
import type { DataFreshnessSource, DataFreshnessState } from '@/utils/dataFreshness'

defineProps<{
  source: DataFreshnessSource
  freshness: DataFreshnessState | null
}>()
</script>

<template>
  <span data-testid="data-freshness"></span>
</template>
