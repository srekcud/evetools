<script setup lang="ts">
defineProps<{
  testId: string
  label: string
}>()
</script>

<template>
  <div :data-testid="testId" class="min-w-0 rounded-xl border border-slate-800 bg-slate-900 p-4">
    <p class="truncate text-xs uppercase tracking-wider text-slate-500" :title="label">{{ label }}</p>
    <p class="mt-1 truncate font-mono text-3xl font-bold" style="font-variant-numeric: tabular-nums;">
      <slot />
    </p>
    <div class="mt-1 text-xs text-slate-500">
      <slot name="meta" />
    </div>
  </div>
</template>
