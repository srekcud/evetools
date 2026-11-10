<script setup lang="ts">
defineProps<{
  message: string
}>()
</script>

<template>
  <div class="bg-amber-900/30 border border-amber-500/50 rounded-lg p-3 flex items-center gap-3">
    <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
    <p class="text-sm text-amber-300">{{ message }}</p>
  </div>
</template>
