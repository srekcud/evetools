<script setup lang="ts">
import type { MiningSiteScan } from '@/stores/miningSite'

defineProps<{ scans: MiningSiteScan[]; typeNames: Record<number, string> }>()
</script>

<template>
  <div></div>
</template>
