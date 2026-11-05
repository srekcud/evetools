<script setup lang="ts">
defineProps<{
  cause: string
}>()
</script>

<template>
  <span
    data-testid="unknown-value"
    class="inline-block px-1.5 py-0.5 border border-dashed border-violet-400 text-violet-300 rounded-sm text-xs font-sans"
  >{{ cause }}</span>
</template>
