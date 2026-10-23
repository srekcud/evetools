<script setup lang="ts">
import { useFormatters } from '@/composables/useFormatters'
import UnknownValue from './UnknownValue.vue'

defineProps<{
  value: number | null
  loading?: boolean
  short?: boolean
}>()

const { formatIsk, formatIskShort } = useFormatters()
</script>

<template>
  <UnknownValue v-if="loading || value === null" :loading="loading" />
  <span v-else>{{ short ? formatIskShort(value) : formatIsk(value) }}</span>
</template>
