<script setup lang="ts">
import type { CharacterSyncFailure } from '@/stores/industry/types'

defineProps<{
  title: string
  failures: CharacterSyncFailure[]
}>()

defineEmits<{
  dismiss: []
}>()
</script>

<template>
  <div />
</template>
