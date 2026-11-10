<script setup lang="ts">
import type { PriceSource } from '@/stores/industry/types'

defineProps<{
  source: PriceSource | null | undefined
}>()
</script>

<template>
  <span v-if="source"></span>
</template>
