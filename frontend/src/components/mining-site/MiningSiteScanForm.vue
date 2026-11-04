<script setup lang="ts">
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'

defineProps<{ site: MiningSite }>()
defineEmits<{ recorded: [scan: MiningSiteScan] }>()
</script>

<template>
  <div></div>
</template>
