<script setup lang="ts">
import type { StockPinKind } from '@/utils/planetaryStockTodo'

defineProps<{
  kind: StockPinKind
  typeId: number
  number: number | undefined
}>()
</script>

<template>
  <span
    v-if="number !== undefined"
    class="ml-1.5 inline-grid h-4 w-4 place-items-center rounded-full bg-amber-500 font-mono text-xs leading-none text-slate-900"
    :data-testid="`stock-pin-${kind}-${typeId}`"
  >{{ number }}</span>
</template>
