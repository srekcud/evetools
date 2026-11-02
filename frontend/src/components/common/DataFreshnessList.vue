<script setup lang="ts">
import DataFreshness from '@/components/common/DataFreshness.vue'
import { useDataFreshnessStore } from '@/stores/dataFreshness'
import type { DataFreshnessSource } from '@/utils/dataFreshness'

defineProps<{
  sources: readonly DataFreshnessSource[]
}>()

const store = useDataFreshnessStore()
</script>

<template>
  <div data-testid="data-freshness-list" class="flex flex-wrap items-center gap-x-4 gap-y-1">
    <DataFreshness
      v-for="source in sources"
      :key="source"
      :source="source"
      :freshness="store.states[source]"
    />
  </div>
</template>
