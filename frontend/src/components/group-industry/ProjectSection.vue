<script setup lang="ts">
defineProps<{
  sectionKey: string
  open: boolean
}>()

defineEmits<{ toggle: [] }>()
</script>

<template>
  <details
    :data-testid="`project-section-${sectionKey}`"
    :data-section="sectionKey"
    :open="open"
    class="group border border-slate-800 bg-slate-900/60"
  >
    <summary
      :data-testid="`project-section-toggle-${sectionKey}`"
      class="flex cursor-pointer list-none select-none items-center gap-2 px-4 py-3 font-display [&::-webkit-details-marker]:hidden text-sm font-semibold uppercase tracking-widest text-slate-300 hover:text-cyan-300"
      role="button"
      tabindex="0"
      :aria-expanded="open"
      @click.prevent="$emit('toggle')"
      @keydown.enter.prevent="$emit('toggle')"
    >
      <svg class="h-3 w-3 flex-none text-slate-500 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
      </svg>
      <slot name="title" />
      <slot name="badge" />
    </summary>
    <div v-if="open" class="px-4 pb-4 pt-2">
      <slot />
    </div>
  </details>
</template>
