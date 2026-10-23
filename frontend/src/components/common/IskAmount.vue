<script setup lang="ts">
import { useFormatters } from '@/composables/useFormatters'

defineProps<{
  value: number | null
  loading?: boolean
  short?: boolean
}>()

const { formatIsk } = useFormatters()
</script>

<template>
  <span>{{ formatIsk(value) }}</span>
</template>
