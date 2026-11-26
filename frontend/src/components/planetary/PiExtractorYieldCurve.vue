<script setup lang="ts">
import type { ExtractorYieldCurve } from '@/stores/planetary'

defineProps<{
  curve: ExtractorYieldCurve | null
  installTime: string | null
  expiryTime: string | null
}>()
</script>

<template>
  <div />
</template>
